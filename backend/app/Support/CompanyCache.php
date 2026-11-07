<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
            $generation = Cache::get($key.':generation', 'initial');
        } catch (\Throwable $exception) {
            report($exception);

            return $callback();
        }
        // Callback exceptions must propagate and a failed cache SET must not execute it twice.
        $value = $callback();
        try {
            Cache::lock($key.':publish', 5)->block(1, function () use ($key, $expiration, $generation, $value): void {
                if (Cache::get($key.':generation', 'initial') === $generation) {
                    Cache::put($key, $value, $expiration);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $value;
    }

    public static function forget(int $companyId, string ...$resources): void
    {
        foreach ($resources as $resource) {
            try {
                $key = self::key($companyId, $resource);
                Cache::lock($key.':publish', 5)->block(1, function () use ($key): void {
                    Cache::forever($key.':generation', (string) Str::uuid());
                    Cache::forget($key);
                });
            } catch (\Throwable $exception) {
                report($exception);
                // Committed writes must remain successful even when Redis/locks fail.
                try {
                    Cache::forget(self::key($companyId, $resource));
                } catch (\Throwable $error) {
                    report($error);
                }
            }
        }
    }
}
