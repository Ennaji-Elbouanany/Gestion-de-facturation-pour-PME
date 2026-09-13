<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::where('company_id', $request->user()->company_id)
            ->with(['client:id,name', 'items'])
            ->latest()
            ->get();

        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateInvoice($request);

        $invoice = DB::transaction(function () use ($request, $data) {
            $invoice = Invoice::create([
                'company_id' => $request->user()->company_id,
                'client_id' => $data['client_id'],
                'user_id' => $request->user()->id,
                'invoice_number' => $this->generateInvoiceNumber($request->user()->company_id),
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($invoice, $data['items']);

            return $invoice->fresh(['client:id,name', 'items']);
        });

        return response()->json($invoice, 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);

        return response()->json($invoice->load(['client:id,name,email,phone,address,city,country', 'items', 'payments']));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);

        $data = $this->validateInvoice($request, true);

        $invoice = DB::transaction(function () use ($invoice, $data) {
            $invoice->update([
                'client_id' => $data['client_id'] ?? $invoice->client_id,
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $invoice->due_date,
                'status' => $data['status'] ?? $invoice->status,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $invoice->notes,
            ]);

            if (isset($data['items'])) {
                $this->syncItems($invoice, $data['items']);
            }

            return $invoice->fresh(['client:id,name', 'items']);
        });

        return response()->json($invoice);
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);
        $invoice->delete();

        return response()->json([
            'message' => 'Facture supprimée avec succès.',
        ]);
    }

    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureBelongsToCompany($request, $invoice);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:draft,sent,paid,partial,overdue,cancelled'],
        ]);

        $invoice->update(['status' => $data['status']]);

        return response()->json($invoice->fresh(['client:id,name', 'items']));
    }

    private function validateInvoice(Request $request, bool $partial = false): array
    {
        $rules = [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'status' => ['sometimes', 'string', 'in:draft,sent,paid,partial,overdue,cancelled'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
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

    private function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();

        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $taxRate = (float) ($item['tax_rate'] ?? 0);
            $subtotal = round($quantity * $unitPrice, 2);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'subtotal' => $subtotal,
                'total' => $total,
            ]);
        }

        $this->recalculateTotals($invoice);
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $items = $invoice->items()->get();

        $subtotal = $items->sum(fn ($item) => (float) $item->subtotal);
        $taxAmount = $items->sum(fn ($item) => (float) $item->total - (float) $item->subtotal);
        $totalAmount = $items->sum(fn ($item) => (float) $item->total);

        $invoice->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($totalAmount, 2),
        ]);
    }

    private function generateInvoiceNumber(int $companyId): string
    {
        // La colonne invoice_number est UNIQUE globalement en base : on ne peut
        // pas compter par entreprise (deux sociétés produiraient FAC-2026-001).
        // On dérive le prochain numéro du dernier numéro réellement utilisé.
        $year = now()->format('Y');
        $pattern = sprintf('FAC-%s-%%', $year);
        $last = Invoice::where('invoice_number', 'like', $pattern)
            ->latest('invoice_number')
            ->value('invoice_number');

        $sequence = 1;

        if ($last) {
            $sequence = (int) \Illuminate\Support\Str::afterLast($last, '-') + 1;
        }

        return sprintf('FAC-%s-%03d', $year, $sequence);
    }

    private function ensureBelongsToCompany(Request $request, Invoice $invoice): void
    {
        abort_unless(
            $invoice->company_id === $request->user()->company_id,
            404
        );
    }
}