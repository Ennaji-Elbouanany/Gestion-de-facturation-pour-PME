<?php

namespace App\Services;

use App\Support\CompanyCache;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

final class FinancialTransaction
{
    public static function run(Closure $work): mixed
    {
        try {
            return DB::transaction($work, 3);
        } catch (\Throwable $exception) {
            $info = $exception instanceof \PDOException ? ($exception->errorInfo ?? []) : [];
            $state = $info[0] ?? null;
            $code = (int) ($info[1] ?? 0);
            if ($exception instanceof DeadlockException || in_array($state, ['40001', '40P01'], true) || in_array($code, [5, 6, 1205, 1213], true)) {
                abort(409, 'Conflit concurrent. Rechargez les données et réessayez.');
            }
            throw $exception;
        }
    }

    public static function invalidate(int $companyId, string ...$resources): void
    {
        DB::afterCommit(function () use ($companyId, $resources): void {
            foreach (array_unique([CompanyCache::INVOICES, CompanyCache::DASHBOARD, ...$resources]) as $resource) {
                try {
                    CompanyCache::forget($companyId, $resource);
                } catch (\Throwable $exception) {
                    // The transaction is committed; still attempt the remaining cache keys.
                    report($exception);
                }
            }
        });
    }
}
