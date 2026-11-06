<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class InvoiceService
{
    public static function locked(User $actor, int $id): Invoice
    {
        return Invoice::where('company_id', $actor->company_id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public static function paid(Invoice $invoice, bool $lock = true): string
    {
        $query = $invoice->payments()->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return MoneyCalculator::sum($query->get()->pluck('amount'));
    }

    public static function assertLedger(Invoice $invoice, string $paid): void
    {
        abort_if(MoneyCalculator::decimal($paid)->isGreaterThan($invoice->total_amount), 409, 'Surpaiement existant : audit financier nécessaire.');
        $raw = $invoice->getRawOriginal('status');
        abort_if($raw === 'paid' && MoneyCalculator::decimal($paid)->isLessThan($invoice->total_amount), 409, 'Ancien statut payé incohérent : audit nécessaire.');
        abort_if($raw === 'partial' && MoneyCalculator::decimal($paid)->isZero(), 409, 'Ancien statut partiel incohérent : audit nécessaire.');
    }

    public static function refreshStatus(Invoice $invoice): void
    {
        $paid = self::paid($invoice);
        $invoice->status = self::status($invoice, $paid);
        $invoice->save();
    }

    public static function status(Invoice $invoice, string $paid): string
    {
        if ($invoice->document_status === 'cancelled') {
            return 'cancelled';
        }
        if ($invoice->document_status === 'draft') {
            return 'draft';
        }
        if (MoneyCalculator::decimal($invoice->total_amount)->isZero()) {
            return 'sent';
        }
        if (MoneyCalculator::decimal($paid)->isEqualTo($invoice->total_amount)) {
            return 'paid';
        }
        if ($invoice->due_date && $invoice->due_date->lt(today())) {
            return 'overdue';
        }

        return MoneyCalculator::decimal($paid)->isPositive() ? 'partial' : 'sent';
    }

    public function update(User $actor, int $id, array $data): Invoice
    {
        return FinancialTransaction::run(function () use ($actor, $id, $data) {
            $invoice = self::locked($actor, $id);
            Gate::forUser($actor)->authorize('update', $invoice);
            abort_if($invoice->archived_at !== null || $invoice->document_status !== 'draft', 409, 'Seul un brouillon actif peut être modifié.');
            abort_if($invoice->payments()->lockForUpdate()->exists(), 409, 'Une facture avec règlement ne peut pas être modifiée.');
            $target = $data['status'] ?? 'draft';
            $invoice->fill(collect($data)->only(['client_id', 'invoice_date', 'due_date', 'notes'])->all());
            $invoice->save();
            if (isset($data['items'])) {
                self::syncItems($invoice, $data['items']);
            }
            if ($target !== 'draft') {
                self::changeState($invoice, $target);
            }
            FinancialTransaction::invalidate($invoice->company_id);

            return $invoice->fresh();
        });
    }

    public function transition(User $actor, int $id, string $target): Invoice
    {
        return FinancialTransaction::run(function () use ($actor, $id, $target) {
            $invoice = self::locked($actor, $id);
            Gate::forUser($actor)->authorize('updateStatus', $invoice);
            self::changeState($invoice, $target);
            FinancialTransaction::invalidate($invoice->company_id);

            return $invoice->fresh();
        });
    }

    public static function changeState(Invoice $invoice, string $target): void
    {
        abort_if($invoice->archived_at !== null, 409, 'Restaurez le brouillon avant de changer son état.');
        $state = $invoice->document_status;
        $paid = self::paid($invoice);
        self::assertLedger($invoice, $paid);
        $next = match ($target) {
            'draft' => 'draft', 'sent' => 'issued', 'cancelled' => 'cancelled', default => null
        };
        abort_if($next === null, 422, 'Le statut financier est calculé à partir des règlements.');
        if ($next === $state) {
            return;
        }
        abort_unless(($state === 'draft' && $next === 'issued') || ($state === 'issued' && $next === 'cancelled'), 409, 'Transition documentaire interdite.');
        abort_if(! MoneyCalculator::decimal($paid)->isZero(), 409, 'Une facture avec règlement ne peut pas être émise ou annulée ainsi.');
        if ($next === 'issued') {
            abort_unless($invoice->items()->exists(), 409, 'La facture doit contenir au moins une ligne.');
            $invoice->load(['company', 'client', 'items']);
            foreach ($invoice->items as $item) {
                foreach (MoneyCalculator::line($item->toArray()) as $field => $amount) {
                    abort_unless(MoneyCalculator::decimal($amount)->isEqualTo($item->$field), 409, 'Lignes incohérentes : audit nécessaire avant émission.');
                }
            }
            $totals = MoneyCalculator::totals($invoice->items->toArray(), $invoice->discount_amount);
            foreach ($totals as $field => $amount) {
                abort_unless(MoneyCalculator::decimal($amount)->isEqualTo($invoice->$field), 409, 'Totaux incohérents : audit nécessaire avant émission.');
            }
            $invoice->issued_snapshot = ['source' => 'at_issuance', 'company' => $invoice->company->toArray(),
                'client' => $invoice->client->toArray(), 'items' => $invoice->items->toArray(),
                'currency' => $invoice->company->currency ?? 'MAD', 'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'notes' => $invoice->notes, ...$totals, 'discount_amount' => $invoice->discount_amount];
            $invoice->issued_at = now();
        }
        $invoice->document_status = $next;
        $invoice->status = $target;
        $invoice->save();
    }

    public static function syncItems(Invoice $invoice, array $items): void
    {
        abort_unless($invoice->document_status === 'draft', 409, 'Les lignes émises sont immuables.');
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [...$item, ...MoneyCalculator::line($item)];
        }
        $totals = MoneyCalculator::totals($rows, $invoice->discount_amount);
        $invoice->items()->delete();
        foreach ($rows as $item) {
            $invoice->items()->create($item);
        }
        $invoice->fill($totals)->save();
    }
}
