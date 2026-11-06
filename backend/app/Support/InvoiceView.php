<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\FinancialAudit;
use App\Services\InvoiceService;
use App\Services\MoneyCalculator;

final class InvoiceView
{
    public static function one(Invoice $invoice, User $actor, bool $details = false): array
    {
        $invoice->loadMissing(['client', 'items', 'payments']);
        $paid = MoneyCalculator::sum($invoice->payments->pluck('amount'));
        $data = $invoice->toArray();
        unset($data['payments'], $data['payments_sum_amount']);
        $data['document_status'] = $invoice->document_status;
        $data['status'] = InvoiceService::status($invoice, $paid);
        if ($invoice->issued_snapshot) {
            $data['client'] = $invoice->issued_snapshot['client'];
            $data['items'] = $invoice->issued_snapshot['items'];
        }
        if ($actor->can('viewAny', Payment::class)) {
            $data['financial_issues'] = app(FinancialAudit::class)->inspect($invoice);
            $data['financial_review_required'] = count($data['financial_issues']) > 0;
            $data['legacy_status'] = $invoice->getRawOriginal('status');
            $data['paid_amount'] = $paid;
            $data['balance_due'] = MoneyCalculator::money(MoneyCalculator::decimal($invoice->total_amount)->minus($paid));
            $data['payment_status'] = MoneyCalculator::decimal($invoice->total_amount)->isZero() ? 'not_required'
                : (MoneyCalculator::decimal($paid)->isEqualTo($invoice->total_amount) ? 'paid' : (MoneyCalculator::decimal($paid)->isPositive() ? 'partial' : 'unpaid'));
            $data['is_overdue'] = $invoice->document_status === 'issued' && $invoice->due_date && $invoice->due_date->lt(today()) && MoneyCalculator::decimal($paid)->isLessThan($invoice->total_amount);
            if ($details) {
                $data['payments'] = $invoice->payments->load('revisions')->toArray();
            }
        }

        return $data;
    }
}
