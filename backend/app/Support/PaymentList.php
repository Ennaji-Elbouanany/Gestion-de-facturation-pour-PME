<?php

namespace App\Support;

use App\Services\MoneyCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentList
{
    public static function response(Request $request, ?int $invoiceId = null): mixed
    {
        $f = ListQuery::filters($request, 'payments');
        $query = DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->leftJoin('clients', fn ($join) => $join->on('clients.id', '=', 'invoices.client_id')->on('clients.company_id', '=', 'invoices.company_id'))->where('invoices.company_id', $request->user()->company_id);
        if ($invoiceId !== null) {
            $query->where('payments.invoice_id', $invoiceId);
        }
        if (! empty($f['client_id'])) {
            $query->where('invoices.client_id', $f['client_id']);
        }
        if (! empty($f['payment_method'])) {
            $query->where('payments.payment_method', $f['payment_method']);
        }
        ListQuery::dates($query, $f, 'payments.payment_date');
        ListQuery::search($query, $f, ['payments.reference', 'invoices.invoice_number', FinancialRead::clientName()]);
        $query->select(['payments.id', 'payments.invoice_id', 'payments.amount', 'payments.payment_date', 'payments.payment_method', 'payments.reference', 'payments.notes', 'payments.version', 'payments.created_at', 'payments.updated_at', 'invoices.invoice_number', 'invoices.client_id'])
            ->selectRaw(FinancialRead::clientName().' AS client_name, '.FinancialRead::currency().' AS currency');
        // Exact filtered total belongs to the full result, never just the visible page.

        $totalsQuery = clone $query;
        $page = ListQuery::page($query, $request, $f, 'payments')->through(function ($row) {
            $data = (array) $row;
            $data['amount'] = MoneyCalculator::money($row->amount);
            $data['invoice'] = ['id' => $row->invoice_id, 'invoice_number' => $row->invoice_number, 'client' => ['id' => $row->client_id, 'name' => $row->client_name]];
            unset($data['client_id'],$data['client_name'],$data['invoice_number']);

            return $data;
        })->toArray();
        // Mixed currencies are represented separately; never show a fabricated global total.
        $sums = $totalsQuery->reorder()->select([])->selectRaw(FinancialRead::currency().' AS currency, '.ExactSql::sum('payments.amount').' AS amount')->groupByRaw(FinancialRead::currency())->get();
        $page['totals_by_currency'] = $sums->map(fn ($row) => ['currency' => $row->currency, 'amount' => $row->currency === 'UNKNOWN' ? null : MoneyCalculator::money($row->amount)])->all();

        return $page;
    }
}
