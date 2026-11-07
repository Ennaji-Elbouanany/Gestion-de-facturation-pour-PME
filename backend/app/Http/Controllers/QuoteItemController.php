<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteItem;
use App\Services\MoneyCalculator;
use App\Services\QuoteService;
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

        $item = app(QuoteService::class)->item($request->user(), $quote->id, null, $data);

        return response()->json($item, 201);
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

        $item = app(QuoteService::class)->item($request->user(), $quote->id, $quoteItem->id, $data);

        return response()->json($item);
    }

    public function destroy(Request $request, Quote $quote, QuoteItem $quoteItem): JsonResponse
    {
        $this->ensureItemBelongsToQuote($request, $quote, $quoteItem);
        $this->authorize('update', $quote);
        app(QuoteService::class)->item($request->user(), $quote->id, $quoteItem->id, null);

        return response()->json(['message' => 'Ligne de devis supprimée avec succès.']);
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
