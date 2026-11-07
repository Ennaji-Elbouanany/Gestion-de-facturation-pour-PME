<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\User;
use App\Services\MoneyCalculator;
use Illuminate\Http\Request;

final class DocumentList
{
    public static function invoices(User $actor, Request $request): mixed
    {
        $f = ListQuery::filters($request, 'invoices');
        $query = FinancialRead::invoices($actor->company_id)->leftJoin('clients', fn ($join) => $join->on('clients.id', '=', 'invoices.client_id')->on('clients.company_id', '=', 'invoices.company_id'));
        ListQuery::archive($query, $f, 'invoices.archived_at');
        ListQuery::dates($query, $f, 'invoices.invoice_date');
        ListQuery::search($query, $f, ['invoices.invoice_number', FinancialRead::clientName()]);
        if (! empty($f['client_id'])) {
            $query->where('invoices.client_id', $f['client_id']);
        }
        if (! empty($f['document_status'])) {
            $query->whereRaw(FinancialRead::state().' = ?', [$f['document_status']]);
        }
        if (! empty($f['status'])) {
            $query->whereRaw('('.FinancialRead::status().') = ?', [$f['status']]);
        }
        $query->select(['invoices.id', 'invoices.company_id', 'invoices.client_id', 'invoices.invoice_number', 'invoices.invoice_date', 'invoices.due_date', 'invoices.archived_at', 'invoices.created_at', 'invoices.updated_at', 'invoices.subtotal', 'invoices.tax_amount', 'invoices.total_amount'])
            ->selectRaw(FinancialRead::state().' AS document_status, '.FinancialRead::status().' AS status, '.FinancialRead::paid().' AS paid_amount, '.FinancialRead::clientName().' AS client_name, '.FinancialRead::currency().' AS currency');

        return ListQuery::page($query, $request, $f, 'invoices')->through(fn ($row) => self::invoice($row, $actor));
    }

    public static function invoice(object $row, User $actor): array
    {
        $data = (array) $row;
        $paid = MoneyCalculator::money($data['paid_amount']);
        $data['client'] = ['id' => $row->client_id, 'name' => $row->client_name];
        unset($data['client_name'], $data['paid_amount']);
        foreach (['subtotal', 'tax_amount', 'total_amount'] as $field) {
            $data[$field] = MoneyCalculator::money($data[$field]);
        }
        if ($actor->can('viewAny', Payment::class)) {
            $data['paid_amount'] = $paid;
            $data['balance_due'] = MoneyCalculator::money(MoneyCalculator::decimal($row->total_amount)->minus($paid));
            $data['is_overdue'] = $row->document_status === 'issued' && $row->due_date && $row->due_date < today()->toDateString() && MoneyCalculator::decimal($data['balance_due'])->isPositive();
            $data['payment_status'] = MoneyCalculator::decimal($row->total_amount)->isZero() ? 'not_required' : (MoneyCalculator::decimal($paid)->isEqualTo($row->total_amount) ? 'paid' : (MoneyCalculator::decimal($paid)->isPositive() ? 'partial' : 'unpaid'));
        }

        return $data;
    }
}
