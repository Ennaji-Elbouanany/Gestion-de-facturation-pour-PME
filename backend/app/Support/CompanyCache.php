<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Predis\Connection\ConnectionException;

final class CompanyCache
{
    public const CLIENTS = 'clients';

    public const PRODUCTS = 'products';

    public const DASHBOARD = 'dashboard-summary';

    public const INVOICES = 'invoices';

    public const QUOTES = 'quotes';

    public static function key(int $companyId, string $resource): string
    {
        return "company:{$companyId}:{$resource}";
    }

    public static function remember(
        string $key,
        DateTimeInterface $expiration,
        Closure $callback,
    ): mixed {
        try {
            return Cache::remember($key, $expiration, $callback);
        } catch (ConnectionException $exception) {
            report($exception);

            return $callback();
        }
    }

    public static function forget(int $companyId, string ...$resources): void
    {
        foreach ($resources as $resource) {
            try {
                Cache::forget(self::key($companyId, $resource));
            } catch (ConnectionException $exception) {
                report($exception);
            }
        }
    }
}
