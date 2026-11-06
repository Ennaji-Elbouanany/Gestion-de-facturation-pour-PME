<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRevision;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PaymentService
{
    public function create(User $actor, int $invoiceId, array $data): Payment
    {
        Validator::make($data, ['amount' => ['required', ...MoneyCalculator::rules(true)], 'payment_date' => ['required', 'date'], 'payment_method' => ['required', 'in:cash,bank_transfer,cheque,credit_card,digital_wallet,other'], 'idempotency_key' => ['nullable', 'uuid']])->validate();

        return FinancialTransaction::run(function () use ($actor, $invoiceId, $data) {
            $invoice = InvoiceService::locked($actor, $invoiceId);
            Gate::forUser($actor)->authorize('create', [Payment::class, $invoice]);
            $fields = $this->fields($data);
            $hash = hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
            if (! empty($data['idempotency_key'])) {
                $existing = $invoice->payments()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Cette clé a déjà été utilisée avec un autre paiement.');

                    return $existing;
                }
            }
            $paid = $this->checkInvoice($invoice);
            $this->checkBalance($invoice, $paid, $fields['amount']);
            $payment = new Payment($fields);
            $payment->invoice_id = $invoice->id;
            $payment->idempotency_key = $data['idempotency_key'] ?? null;
            $payment->request_hash = $hash;
            $payment->save();
            InvoiceService::refreshStatus($invoice);
            FinancialTransaction::invalidate($invoice->company_id);

            return $payment->fresh();
        });
    }

    public function update(User $actor, int $invoiceId, int $paymentId, array $data): Payment
    {
        Validator::make($data, ['amount' => ['sometimes', ...MoneyCalculator::rules(true)], 'version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();

        return FinancialTransaction::run(function () use ($actor, $invoiceId, $paymentId, $data) {
            $invoice = InvoiceService::locked($actor, $invoiceId);
            $payment = $invoice->payments()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $payment);
            $paid = $this->checkInvoice($invoice);
            abort_unless($payment->version === (int) $data['version'], 409, 'Ce règlement a été corrigé. Rechargez-le avant de réessayer.');
            $before = $payment->only(['amount', 'payment_date', 'payment_method', 'reference', 'notes', 'version']);
            $fields = $this->fields(array_merge($payment->toArray(), $data));
            $this->checkBalance($invoice, MoneyCalculator::money(MoneyCalculator::decimal($paid)->minus($payment->amount)), $fields['amount']);
            $payment->fill($fields);
            $payment->version++;
            $payment->save();
            PaymentRevision::create(['payment_id' => $payment->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'reason' => $data['reason'], 'before' => $before,
                'after' => $payment->only(['amount', 'payment_date', 'payment_method', 'reference', 'notes', 'version']), 'created_at' => now()]);
            InvoiceService::refreshStatus($invoice);
            FinancialTransaction::invalidate($invoice->company_id);

            return $payment->fresh()->load('revisions');
        });
    }

    private function checkInvoice(Invoice $invoice): string
    {
        abort_unless($invoice->document_status === 'issued' && $invoice->archived_at === null, 409, 'Les règlements exigent une facture émise, non annulée et non archivée.');
        $paid = InvoiceService::paid($invoice);
        InvoiceService::assertLedger($invoice, $paid);

        return $paid;
    }

    private function checkBalance(Invoice $invoice, string $paid, string $amount): void
    {
        if (MoneyCalculator::decimal($paid)->plus($amount)->isGreaterThan($invoice->total_amount)) {
            throw ValidationException::withMessages(['amount' => 'Le montant dépasse le solde restant.']);
        }
    }

    private function fields(array $data): array
    {
        return ['amount' => MoneyCalculator::bounded($data['amount']),
            'payment_date' => substr((string) $data['payment_date'], 0, 10), 'payment_method' => $data['payment_method'],
            'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
