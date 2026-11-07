<?php

namespace App\Support;

use App\Services\MoneyCalculator;
use Illuminate\Support\Facades\DB;

final class ExactSql
{
    private static ?\WeakMap $registered = null;

    public static function boot(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        $pdo = DB::connection()->getPdo();
        self::$registered ??= new \WeakMap;
        if (isset(self::$registered[$pdo])) {
            return;
        }
        $aggregate = fn ($sum, $row, $value) => (string) MoneyCalculator::decimal($sum ?? '0')->plus((string) ($value ?? '0'));
        $final = fn ($sum, $rows) => MoneyCalculator::money($sum ?? '0');
        if (method_exists($pdo, 'createAggregate')) {
            $pdo->createAggregate('billing_sum', $aggregate, $final, 1);
        } else {
            $pdo->sqliteCreateAggregate('billing_sum', $aggregate, $final, 1);
        }
        $pdo->sqliteCreateFunction('billing_sub', fn ($a, $b) => MoneyCalculator::money(MoneyCalculator::decimal((string) ($a ?? '0'))->minus((string) ($b ?? '0'))), 2);
        $pdo->sqliteCreateFunction('billing_cmp', fn ($a, $b) => MoneyCalculator::decimal((string) ($a ?? '0'))->compareTo((string) ($b ?? '0')), 2);
        $pdo->sqliteCreateFunction('billing_valid', fn ($value) => is_string($value) && preg_match('/^\d{1,10}(?:[.]\d{1,2})?$/D', $value) ? 1 : 0, 1);
        $pdo->sqliteCreateCollation('billing_money', fn ($a, $b) => MoneyCalculator::decimal($a)->compareTo($b));
        self::$registered[$pdo] = true;
    }

    public static function sum(string $expression): string
    {
        self::boot();

        return DB::getDriverName() === 'sqlite' ? "COALESCE(billing_sum(CAST($expression AS TEXT)), '0.00')" : "COALESCE(SUM(CAST($expression AS DECIMAL(65,2))), 0)";
    }

    public static function subtract(string $a, string $b): string
    {
        return DB::getDriverName() === 'sqlite' ? "billing_sub(CAST($a AS TEXT), CAST($b AS TEXT))" : "($a - $b)";
    }

    public static function compare(string $a, string $b): string
    {
        return DB::getDriverName() === 'sqlite' ? "billing_cmp(CAST($a AS TEXT), CAST($b AS TEXT))" : "(CASE WHEN $a > $b THEN 1 WHEN $a < $b THEN -1 ELSE 0 END)";
    }

    public static function json(string $column, string $path): string
    {
        return DB::getDriverName() === 'sqlite' ? "json_extract($column, '$path')" : "JSON_UNQUOTE(JSON_EXTRACT($column, '$path'))";
    }

    public static function moneyOrder(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? $column.' COLLATE billing_money DESC' : $column.' DESC';
    }

    public static function month(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "substr($column, 1, 7)" : "DATE_FORMAT($column, '%Y-%m')";
    }
}
