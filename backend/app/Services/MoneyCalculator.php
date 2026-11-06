<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class MoneyCalculator
{
    public const MAX = '9999999999.99';

    public static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'));
    }

    public static function money(mixed $value): string
    {
        return (string) self::decimal($value)->toScale(2, RoundingMode::HalfUp);
    }

    public static function bounded(mixed $value): string
    {
        $amount = self::decimal($value)->toScale(2, RoundingMode::HalfUp);
        if ($amount->isNegative() || $amount->isGreaterThan(self::MAX)) {
            throw ValidationException::withMessages(['amount' => 'Montant hors limites du document.']);
        }

        return (string) $amount;
    }

    public static function sum(iterable $amounts): string
    {
        $total = self::decimal('0');
        foreach ($amounts as $amount) {
            $total = $total->plus((string) $amount);
        }

        return self::money($total);
    }

    public static function line(array $line): array
    {
        $subtotal = self::bounded(self::decimal($line['quantity'])->multipliedBy((string) $line['unit_price']));
        $tax = self::bounded(self::decimal($subtotal)->multipliedBy((string) ($line['tax_rate'] ?? '0'))->dividedBy('100', 2, RoundingMode::HalfUp));

        return ['subtotal' => $subtotal, 'total' => self::bounded(self::decimal($subtotal)->plus($tax))];
    }

    public static function totals(iterable $items, mixed $discount = '0'): array
    {
        $subtotals = [];
        $totals = [];
        foreach ($items as $item) {
            $subtotals[] = $item['subtotal'];
            $totals[] = $item['total'];
        }
        $subtotal = self::bounded(self::sum($subtotals));
        $gross = self::bounded(self::sum($totals));

        return ['subtotal' => $subtotal,
            'tax_amount' => self::bounded(self::decimal($gross)->minus($subtotal)),
            'total_amount' => self::bounded(self::decimal($gross)->minus(self::bounded($discount)))];
    }

    public static function rules(bool $positive = false): array
    {
        return ['numeric', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', $positive ? 'min:0.01' : 'min:0', 'max:'.self::MAX];
    }
}
