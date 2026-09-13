<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToCompany($request, $invoice);

        $payments = $invoice->payments()
            ->latest()
            ->get();

        return response()->json($payments);
    }

    /**
     * Liste tous les paiements de l'entreprise, avec la facture et le client associés.
     */
    public function all(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->whereHas('invoice', static function ($query) use ($request) {
                $query->where('company_id', $request->user()->company_id);
            })
            ->with(['invoice.client'])
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($payments);
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->ensureInvoiceBelongsToCompany($request, $invoice);
        abort_unless($invoice->status !== 'cancelled', 422, 'Impossible d’ajouter un paiement à une facture annulée.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->ensureWithinBalance($invoice, (float) $data['amount']);

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $data['amount'],
            'payment_date' => $data['payment_date'],
            'payment_method' => $data['payment_method'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->refreshInvoiceStatus($invoice);

        return response()->json($payment->fresh(), 201);
    }

    public function show(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);

        return response()->json($payment);
    }

    public function update(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);

        $data = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'payment_date' => ['sometimes', 'required', 'date'],
            'payment_method' => ['sometimes', 'required', 'string', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $amount = (float) ($data['amount'] ?? $payment->amount);
        $this->ensureWithinBalance($invoice, $amount, $payment);

        $payment->update([
            'amount' => $data['amount'] ?? $payment->amount,
            'payment_date' => $data['payment_date'] ?? $payment->payment_date,
            'payment_method' => $data['payment_method'] ?? $payment->payment_method,
            'reference' => array_key_exists('reference', $data) ? $data['reference'] : $payment->reference,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $payment->notes,
        ]);

        $this->refreshInvoiceStatus($invoice);

        return response()->json($payment->fresh());
    }

    public function destroy(Request $request, Invoice $invoice, Payment $payment): JsonResponse
    {
        $this->ensurePaymentBelongsToInvoice($request, $invoice, $payment);
        $payment->delete();

        $this->refreshInvoiceStatus($invoice);

        return response()->json([
            'message' => 'Paiement supprimé avec succès.',
        ]);
    }

    /**
     * Vérifie qu'un montant ne dépasse pas le solde restant de la facture.
     * Le paiement $excluded (mise à jour) est ignoré du cumul déjà encaissé.
     */
    private function ensureWithinBalance(Invoice $invoice, float $amount, ?Payment $excluded = null): void
    {
        $paidTotal = 0.0;

        foreach ($invoice->payments()->get() as $existing) {
            if ($excluded && $existing->id === $excluded->id) {
                continue;
            }

            $paidTotal += (float) $existing->amount;
        }

        $balance = round((float) $invoice->total_amount - $paidTotal, 2);

        abort_unless(
            $amount <= $balance + 0.001,
            422,
            sprintf('Le montant dépasse le solde restant de la facture (%s).', number_format($balance, 2, ',', ' '))
        );
    }

    /**
     * Recalcule le statut financier de la facture en fonction des paiements :
     * intégralement payée -> 'paid' ; partiellement -> 'partial' ; plus aucun paiement -> 'sent'.
     */
    private function refreshInvoiceStatus(Invoice $invoice): void
    {
        $paidTotal = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total_amount;

        if ($paidTotal > 0) {
            $invoice->update([
                'status' => $total > 0 && $paidTotal >= $total ? 'paid' : 'partial',
            ]);

            return;
        }

        if (in_array($invoice->status, ['paid', 'partial'])) {
            $invoice->update(['status' => 'sent']);
        }
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
