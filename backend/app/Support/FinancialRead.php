<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FinancialRead
{
    public static function state(string $alias = 'invoices'): string
    {
        return "COALESCE($alias.document_status, CASE WHEN $alias.status IN ('draft','cancelled') THEN $alias.status ELSE 'issued' END)";
    }

    public static function currency(string $alias = 'invoices'): string
    {
        $value = ExactSql::json("$alias.issued_snapshot", '$.currency');

        return "CASE WHEN $value IN ('MAD','EUR','USD') THEN $value ELSE 'UNKNOWN' END";
    }

    public static function ledger(int $companyId): Builder
    {
        ExactSql::boot();

        return DB::table('payments as lp')->join('invoices as li', 'li.id', '=', 'lp.invoice_id')
            ->where('li.company_id', $companyId)->groupBy('lp.invoice_id')
            ->select('lp.invoice_id')->selectRaw('COUNT(*) AS payment_count, SUM(CASE WHEN lp.amount <= 0 THEN 1 ELSE 0 END) AS invalid_payment_count')->selectRaw(ExactSql::sum('lp.amount').' AS paid_amount');
    }

    public static function invoices(int $companyId): Builder
    {
        return DB::table('invoices')->where('invoices.company_id', $companyId)
            ->leftJoinSub(self::ledger($companyId), 'ledger', 'ledger.invoice_id', '=', 'invoices.id');
    }

    public static function paid(): string
    {
        return 'COALESCE(ledger.paid_amount, 0)';
    }

    public static function status(): string
    {
        $state = self::state();
        $paid = self::paid();
        $zero = ExactSql::compare('invoices.total_amount', '0');
        $equal = ExactSql::compare('invoices.total_amount', $paid);
        $positive = ExactSql::compare($paid, '0');
        $today = today()->toDateString();

        return "CASE WHEN $state = 'cancelled' THEN 'cancelled' WHEN $state = 'draft' THEN 'draft' WHEN $zero = 0 THEN 'sent' WHEN $equal = 0 THEN 'paid' WHEN invoices.due_date < '$today' THEN 'overdue' WHEN $positive > 0 THEN 'partial' ELSE 'sent' END";
    }

    public static function clientName(string $alias = 'invoices'): string
    {
        return 'COALESCE('.ExactSql::json("$alias.issued_snapshot", '$.client.name').", clients.name, 'Client inconnu')";
    }
}
