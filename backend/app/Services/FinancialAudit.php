<?php

namespace App\Services;

use App\Models\Invoice;

final class FinancialAudit
{
    public function inspect(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'payments']);
        $issues = [];
        $paid = MoneyCalculator::sum($invoice->payments->pluck('amount'));
        if (MoneyCalculator::decimal($paid)->isGreaterThan($invoice->total_amount)) {
            $issues[] = 'overpayment';
        }
        if ($invoice->document_status !== 'issued' && ! MoneyCalculator::decimal($paid)->isZero()) {
            $issues[] = 'payments_on_nonissued_invoice';
        }
        if ($invoice->getRawOriginal('status') === 'paid' && ! MoneyCalculator::decimal($paid)->isEqualTo($invoice->total_amount)) {
            $issues[] = 'paid_status_mismatch';
        }
        if ($invoice->getRawOriginal('status') === 'partial' && (MoneyCalculator::decimal($paid)->isZero() || ! MoneyCalculator::decimal($paid)->isLessThan($invoice->total_amount))) {
            $issues[] = 'partial_status_mismatch';
        }
        foreach ($invoice->payments as $payment) {
            if (! MoneyCalculator::decimal($payment->amount)->isPositive()) {
                $issues[] = 'nonpositive_payment';
            }
        }
        if ($invoice->items->isEmpty()) {
            $issues[] = 'missing_lines';
        }
        try {
            $totals = MoneyCalculator::totals($invoice->items->toArray(), $invoice->discount_amount);
            foreach ($totals as $field => $amount) {
                if (! MoneyCalculator::decimal($amount)->isEqualTo($invoice->$field)) {
                    $issues[] = 'document_totals_mismatch';
                }
            }
            foreach ($invoice->items as $item) {
                foreach (MoneyCalculator::line($item->toArray()) as $field => $amount) {
                    if (! MoneyCalculator::decimal($amount)->isEqualTo($item->$field)) {
                        $issues[] = 'line_totals_mismatch';
                    }
                }
            }
        } catch (\Throwable $exception) {
            $issues[] = 'invalid_decimal_or_bounds';
        }
        if ($invoice->document_status === 'issued' && ! $invoice->issued_snapshot) {
            $issues[] = 'legacy_snapshot_unavailable';
        }

        return array_values(array_unique($issues));
    }
}
