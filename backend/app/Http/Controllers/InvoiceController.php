<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ArchivesDrafts;
use App\Models\Invoice;
use App\Services\DocumentNumberService;
use App\Services\FinancialTransaction;
use App\Services\InvoiceService;
use App\Services\MoneyCalculator;
use App\Support\CompanyCache;
use App\Support\DocumentList;
use App\Support\InvoiceView;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    use ArchivesDrafts;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);
        if (ListQuery::requested($request)) {
            return response()->json(DocumentList::invoices($request->user(), $request));
        }
        $companyId = $request->user()->company_id;
        $invoices = Invoice::where('company_id', $companyId)->with(['client', 'items', 'payments'])->latest()->get();

        return response()->json($invoices->map(fn ($invoice) => InvoiceView::one($invoice, $request->user())));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);
        $data = $this->validateInvoice($request);

        $invoice = FinancialTransaction::run(function () use ($request, $data) {
            $invoice = Invoice::create([
                'company_id' => $request->user()->company_id,
                'client_id' => $data['client_id'],
                'user_id' => $request->user()->id,
                'invoice_number' => DocumentNumberService::next($request->user()->company_id, 'invoice'),
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->document_status = 'draft';
            $invoice->save();
            InvoiceService::syncItems($invoice, $data['items']);
            if (($data['status'] ?? 'draft') !== 'draft') {
                InvoiceService::changeState($invoice, $data['status']);
            }
            FinancialTransaction::invalidate($invoice->company_id);

            return $invoice->fresh(['client:id,name,deleted_at', 'items']);
        });

        return response()->json(InvoiceView::one($invoice, $request->user()), 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);
        $this->authorize('view', $invoice);

        $request->validate(['include_payments' => ['sometimes', 'boolean']]);

        return response()->json(InvoiceView::one($invoice, $request->user(), ! $request->has('include_payments') || $request->boolean('include_payments')));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);
        $this->authorize('update', $invoice);
        abort_if($invoice->archived_at !== null, 409, 'Restaurez le brouillon avant de le modifier.');

        $data = $this->validateInvoice($request, true, $invoice->client_id);

        $invoice = app(InvoiceService::class)->update($request->user(), $invoice->id, $data);

        return response()->json(InvoiceView::one($invoice, $request->user()));
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);
        $this->authorize('view', $invoice);

        return response()->json(['message' => 'Ce document doit être conservé. Vous pouvez archiver un brouillon.'], 409);
    }

    public function archive(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->changeDraftArchive($request, $invoice, true);
    }

    public function restore(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->changeDraftArchive($request, $invoice, false);
    }

    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);
        $this->authorize('updateStatus', $invoice);
        abort_if($invoice->archived_at !== null, 409, 'Restaurez le brouillon avant de le modifier.');

        $data = $request->validate([
            'status' => ['required', 'string', 'in:draft,sent,cancelled'],
        ]);

        $invoice = app(InvoiceService::class)->transition($request->user(), $invoice->id, $data['status']);

        return response()->json(InvoiceView::one($invoice, $request->user()));
    }

    private function validateInvoice(Request $request, bool $partial = false, ?int $existingClientId = null): array
    {
        $companyId = $request->user()->company_id;
        if ($request->exists('status') && $request->input('status') !== 'draft') {
            $this->authorize('updateAny', Invoice::class);
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
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'status' => ['sometimes', 'string', 'in:draft,sent,cancelled'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', ...MoneyCalculator::rules(true)],
            'items.*.unit_price' => ['required', ...MoneyCalculator::rules()],
            'items.*.tax_rate' => ['nullable', ...MoneyCalculator::rules(), 'max:100'],
        ];

        if ($partial) {
            foreach ($rules as $field => &$rule) {
                if (! str_starts_with($field, 'items.*.')) {
                    $rule = ['sometimes', ...$rule];
                }
            }
            unset($rule);
            // Compare effective dates under the invoice lock in InvoiceService.
            $rules['due_date'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        }

        return $request->validate($rules);
    }

    private function ensureBelongsToCompany(Request $request, Invoice $invoice): void
    {
        abort_unless(
            $invoice->company_id === $request->user()->company_id,
            404
        );
    }

    private function forgetCompanyCaches(int $companyId): void
    {
        CompanyCache::forget(
            $companyId,
            CompanyCache::INVOICES,
            CompanyCache::DASHBOARD,
        );
    }
}
