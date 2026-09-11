<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteItemController extends Controller
{
    public function index(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureQuoteBelongsToCompany($request, $quote);

        $items = $quote->items()
            ->with('product:id,name')
            ->latest()
            ->get();

        return response()->json($items);
    }

    public function store(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureQuoteBelongsToCompany($request, $quote);

        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $totals = $this->calculateTotals($data);

        $item = QuoteItem::create([
            'quote_id' => $quote->id,
            'product_id' => $data['product_id'] ?? null,
            'description' => $data['description'],
            'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'],
            'tax_rate' => $data['tax_rate'] ?? 0,
            'subtotal' => $totals['subtotal'],
            'total' => $totals['total'],
        ]);

        $this->recalculateQuoteTotals($quote);

        return response()->json($item->fresh(['product:id,name']), 201);
    }

    public function show(Request $request, Quote $quote, QuoteItem $quoteItem): JsonResponse
    {
        $this->ensureItemBelongsToQuote($request, $quote, $quoteItem);

        return response()->json($quoteItem->load('product:id,name'));
    }

    public function update(Request $request, Quote $quote, QuoteItem $quoteItem): JsonResponse
    {
        $this->ensureItemBelongsToQuote($request, $quote, $quoteItem);

        $data = $request->validate([
            'product_id' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $merged = array_merge($quoteItem->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate']), $data);
        $totals = $this->calculateTotals($merged);

        $quoteItem->update([
            ...$data,
            'subtotal' => $totals['subtotal'],
            'total' => $totals['total'],
        ]);

        $this->recalculateQuoteTotals($quote);

        return response()->json($quoteItem->fresh(['product:id,name']));
    }

    public function destroy(Request $request, Quote $quote, QuoteItem $quoteItem): JsonResponse
    {
        $this->ensureItemBelongsToQuote($request, $quote, $quoteItem);
        $quoteItem->delete();

        $this->recalculateQuoteTotals($quote);

        return response()->json([
            'message' => 'Ligne de devis supprimée avec succès.',
        ]);
    }

    private function calculateTotals(array $data): array
    {
        $quantity = (float) ($data['quantity'] ?? 0);
        $unitPrice = (float) ($data['unit_price'] ?? 0);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $subtotal = round($quantity * $unitPrice, 2);
        $total = round($subtotal + ($subtotal * $taxRate / 100), 2);

        return ['subtotal' => $subtotal, 'total' => $total];
    }

    private function recalculateQuoteTotals(Quote $quote): void
    {
        $items = $quote->items()->get();

        $quote->update([
            'subtotal' => round($items->sum(fn ($item) => (float) $item->subtotal), 2),
            'tax_amount' => round($items->sum(fn ($item) => (float) $item->total - (float) $item->subtotal), 2),
            'total_amount' => round($items->sum(fn ($item) => (float) $item->total), 2),
        ]);
    }

    private function ensureQuoteBelongsToCompany(Request $request, Quote $quote): void
    {
        abort_unless(
            $quote->company_id === $request->user()->company_id,
            404
        );
    }

    private function ensureItemBelongsToQuote(Request $request, Quote $quote, QuoteItem $quoteItem): void
    {
        $this->ensureQuoteBelongsToCompany($request, $quote);

        abort_unless(
            $quoteItem->quote_id === $quote->id,
            404
        );
    }
}