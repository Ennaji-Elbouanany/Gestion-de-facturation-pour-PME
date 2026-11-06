<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArchivesDrafts;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Support\CompanyCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QuoteController extends Controller
{
    use ArchivesDrafts;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quote::class);
        $companyId = $request->user()->company_id;
        $quotes = CompanyCache::remember(
            CompanyCache::key($companyId, CompanyCache::QUOTES),
            now()->addMinutes(2),
            fn () => Quote::where('company_id', $companyId)
                ->with(['client:id,name,deleted_at', 'items'])
                ->latest()
                ->get()
        );

        return response()->json($quotes);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Quote::class);
        $data = $this->validateQuote($request);

        $quote = DB::transaction(function () use ($request, $data) {
            $quote = Quote::create([
                'company_id' => $request->user()->company_id,
                'client_id' => $data['client_id'],
                'user_id' => $request->user()->id,
                'quote_number' => $this->generateQuoteNumber($request->user()->company_id),
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($quote, $data['items']);

            return $quote->fresh(['client:id,name,deleted_at', 'items']);
        });

        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($quote, 201);
    }

    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('view', $quote);

        return response()->json($quote->load(['client:id,name,email,phone,address,city,country,deleted_at', 'items']));
    }

    public function update(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('update', $quote);
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de le modifier.');

        $data = $this->validateQuote($request, true, $quote->client_id);

        $quote = DB::transaction(function () use ($quote, $data) {
            $quote->update([
                'client_id' => $data['client_id'] ?? $quote->client_id,
                'quote_date' => $data['quote_date'] ?? $quote->quote_date,
                'valid_until' => array_key_exists('valid_until', $data) ? $data['valid_until'] : $quote->valid_until,
                'status' => $data['status'] ?? $quote->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $quote->notes,
            ]);

            if (isset($data['items'])) {
                $this->syncItems($quote, $data['items']);
            }

            return $quote->fresh(['client:id,name,deleted_at', 'items']);
        });

        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($quote);
    }

    public function destroy(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('view', $quote);

        return response()->json(['message' => 'Ce document doit être conservé. Vous pouvez archiver un brouillon.'], 409);
    }

    public function archive(Request $request, Quote $quote): JsonResponse
    {
        return $this->changeDraftArchive($request, $quote, true);
    }

    public function restore(Request $request, Quote $quote): JsonResponse
    {
        return $this->changeDraftArchive($request, $quote, false);
    }

    public function updateStatus(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('updateStatus', $quote);
        if ($request->input('status') === 'converted' && $quote->status !== 'converted') {
            $this->authorize('convert', $quote);
        }
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de le modifier.');

        $data = $request->validate([
            'status' => ['required', 'string', 'in:draft,sent,accepted,rejected,expired,converted'],
        ]);

        $quote->update(['status' => $data['status']]);
        $this->forgetCompanyCaches($request->user()->company_id);

        return response()->json($quote->fresh(['client:id,name,deleted_at', 'items']));
    }

    public function convertToInvoice(Request $request, Quote $quote): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $quote);
        $this->authorize('convert', $quote);
        abort_if($quote->archived_at !== null, 409, 'Restaurez le brouillon avant de le modifier.');

        if ($quote->status === 'converted') {
            return response()->json([
                'message' => 'Ce devis a déjà été converti en facture.',
            ], 422);
        }

        $invoice = DB::transaction(function () use ($request, $quote) {
            $invoice = Invoice::create([
                'company_id' => $quote->company_id,
                'client_id' => $quote->client_id,
                'user_id' => $request->user()->id,
                'invoice_number' => $this->generateInvoiceNumber($quote->company_id),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'subtotal' => $quote->subtotal,
                'tax_amount' => $quote->tax_amount,
                'discount_amount' => $quote->discount_amount,
                'total_amount' => $quote->total_amount,
                'status' => 'draft',
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'subtotal' => $item->subtotal,
                    'total' => $item->total,
                ]);
            }

            $quote->update(['status' => 'converted']);

            return $invoice->fresh(['client:id,name,deleted_at', 'items']);
        });

        $this->forgetCompanyCaches(
            $request->user()->company_id,
            includeInvoices: true,
        );

        return response()->json($invoice, 201);
    }

    private function validateQuote(Request $request, bool $partial = false, ?int $existingClientId = null): array
    {
        $companyId = $request->user()->company_id;
        if ($request->input('status') === 'converted' && $request->route('quote')?->status !== 'converted') {
            $this->authorize('convert', $request->route('quote') ?? new Quote(['company_id' => $companyId]));
        }

        $rules = [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where(function ($query) use ($companyId, $existingClientId) {
                $query->where('company_id', $companyId)->where(function ($clients) use ($existingClientId) {
                    $clients->whereNull('deleted_at');
                    if ($existingClientId !== null) {
                        $clients->orWhere('id', $existingClientId);
                    }
                });
            })],
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'status' => ['sometimes', 'string', 'in:draft,sent,accepted,rejected,expired,converted'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        if ($partial) {
            $rules = array_map(fn ($rule) => ['sometimes', ...(is_array($rule) ? $rule : [$rule])], $rules);
        }

        return $request->validate($rules);
    }

    private function syncItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $subtotal = round($quantity * $unitPrice, 2);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            QuoteItem::create([
                'quote_id' => $quote->id,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'subtotal' => $subtotal,
                'total' => $total,
            ]);
        }

        $this->recalculateTotals($quote);
    }

    private function recalculateTotals(Quote $quote): void
    {
        $items = $quote->items()->get();

        $subtotal = $items->sum(fn ($item) => (float) $item->subtotal);
        $taxAmount = $items->sum(fn ($item) => (float) $item->total - (float) $item->subtotal);
        $totalAmount = $items->sum(fn ($item) => (float) $item->total);

        $quote->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ]);
    }

    private function generateQuoteNumber(int $companyId): string
    {
        // La colonne quote_number est UNIQUE globalement en base : on ne peut
        // pas compter par entreprise (deux sociétés produiraient DEV-2026-001).
        // On dérive le prochain numéro du dernier numéro réellement utilisé.
        $year = now()->format('Y');
        $pattern = sprintf('DEV-%s-%%', $year);
        $last = Quote::where('quote_number', 'like', $pattern)
            ->latest('quote_number')
            ->value('quote_number');

        $sequence = 1;

        if ($last) {
            $sequence = (int) Str::afterLast($last, '-') + 1;
        }

        return sprintf('DEV-%s-%03d', $year, $sequence);
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        // Même logique que pour InvoiceController : numéro unique global.
        $year = now()->format('Y');
        $pattern = sprintf('FAC-%s-%%', $year);
        $last = Invoice::where('invoice_number', 'like', $pattern)
            ->latest('invoice_number')
            ->value('invoice_number');

        $sequence = 1;

        if ($last) {
            $sequence = (int) Str::afterLast($last, '-') + 1;
        }

        return sprintf('FAC-%s-%03d', $year, $sequence);
    }

    private function ensureBelongsToCompany(Request $request, Quote $quote): void
    {
        abort_unless(
            $quote->company_id === $request->user()->company_id,
            404
        );
    }

    private function forgetCompanyCaches(int $companyId, bool $includeInvoices = false): void
    {
        $resources = [
            CompanyCache::QUOTES,
            CompanyCache::DASHBOARD,
        ];

        if ($includeInvoices) {
            $resources[] = CompanyCache::INVOICES;
        }

        CompanyCache::forget($companyId, ...$resources);
    }
}
