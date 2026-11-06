<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\MoneyCalculator;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToCompany($request, $invoice);
        $this->authorize('viewAny', [Payment::class, $invoice]);

        $payments = $invoice->payments()
            ->with('revisions')->latest()
            ->get();

        foreach ($payments as $entry) {
            if ($entry->relationLoaded('invoice') && $entry->invoice?->issued_snapshot) {
                $entry->invoice->setRelation('client', (new Client)->setRawAttributes($entry->invoice->issued_snapshot['client'], true));
            }
        }

        return response()->json($payments);
    }

    /**
     * Liste tous les paiements de l'entreprise, avec la facture et le client associés.
     */
    public function all(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);
        $payments = Payment::query()
            ->whereHas('invoice', static function ($query) use ($request) {
                $query->where('company_id', $request->user()->company_id);
            })
            ->with(['invoice.client', 'revisions'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get();

        foreach ($payments as $entry) {
            if ($entry->relationLoaded('invoice') && $entry->invoice?->issued_snapshot) {
                $entry->invoice->setRelation('client', (new Client)->setRawAttributes($entry->invoice->issued_snapshot['client'], true));
            }
        }

        return response()->json($payments);
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToCompany($request, $invoice);
        $this->authorize('create', [Payment::class, $invoice]);
        abort_if($invoice->archived_at !== null, 409, 'Restaurez le brouillon avant de gérer ses règlements.');

        $data = $request->validate([
            'amount' => ['required', ...MoneyCalculator::rules(true)],
            'idempotency_key' => ['nullable', 'uuid'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = app(PaymentService::class)->create($request->user(), $invoice->id, $data);

        return response()->json($payment, 201);
    }

    public function show(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);
        $this->authorize('view', $payment);

        return response()->json($payment->load('revisions'));
    }

    public function update(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);
        $this->authorize('update', $payment);
        abort_if($invoice->archived_at !== null, 409, 'Restaurez le brouillon avant de gérer ses règlements.');

        $data = $request->validate([
            'amount' => ['sometimes', 'required', ...MoneyCalculator::rules(true)],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'payment_date' => ['sometimes', 'required', 'date'],
            'payment_method' => ['sometimes', 'required', 'string', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $payment = app(PaymentService::class)->update($request->user(), $invoice->id, $payment->id, $data);

        return response()->json($payment);
    }

    public function destroy(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);
        $this->authorize('view', $payment);

        return response()->json(['message' => 'Un règlement enregistré doit être conservé et ne peut pas être supprimé.'], 409);
    }

    private function ensureInvoiceBelongsToCompany(Request $request, Invoice $invoice): void
    {
        abort_unless(
            $invoice->company_id === $request->user()->company_id,
            404
        );
    }

    private function ensurePaymentBelongsToInvoice(Request $request, Invoice $invoice, Payment $payment): void
    {
        $this->ensureInvoiceBelongsToCompany($request, $invoice);

        abort_unless(
            $payment->invoice_id === $invoice->id,
            404
        );
    }
}
