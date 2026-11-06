<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\MoneyCalculator;
use App\Support\CompanyCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteItemController extends Controller
{
    public function index(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureQuoteBelongsToCompany($request, $quote);
        $this->authorize('view', $quote);

        $items = $quote->items()
            ->with('product:id,name')
            ->latest()
            ->get();

        return response()->json($items);
    }

    public function store(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureQuoteBelongsToCompany($request, $quote);
        $this->authorize('update', $quote);
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de modifier ses lignes.');

        $data = $request->validate([
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $request->user()->company_id)],
            'description' => ['required', 'string', 'max:255'],
            'quantity' => ['required', ...MoneyCalculator::rules(true)],
            'unit_price' => ['required', ...MoneyCalculator::rules()],
            'tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100'],
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
        $this->authorize('view', $quote);

        return response()->json($quoteItem->load('product:id,name'));
    }

    public function update(Request $request, Quote $quote, QuoteItem $quoteItem): JsonResponse
    {
        $this->ensureItemBelongsToQuote($request, $quote, $quoteItem);
        $this->authorize('update', $quote);
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de modifier ses lignes.');

        $data = $request->validate([
            'product_id' => ['sometimes', 'nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $request->user()->company_id)],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => ['sometimes', 'required', ...MoneyCalculator::rules(true)],
            'unit_price' => ['sometimes', 'required', ...MoneyCalculator::rules()],
            'tax_rate' => ['sometimes', 'nullable', ...MoneyCalculator::rules(), 'max:100'],
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
        $this->authorize('update', $quote);
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de modifier ses lignes.');
        $quoteItem->delete();

        $this->recalculateQuoteTotals($quote);

        return response()->json([
            'message' => 'Ligne de devis supprimée avec succès.',
        ]);
    }

    private function calculateTotals(array $data): array
    {
        return MoneyCalculator::line($data);
    }

    private function recalculateQuoteTotals(Quote $quote): void
    {
        $items = $quote->items()->get();

        $quote->update(MoneyCalculator::totals($items->toArray(), $quote->discount_amount));
        CompanyCache::forget($quote->company_id, CompanyCache::QUOTES);
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
