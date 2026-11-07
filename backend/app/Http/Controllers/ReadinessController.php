<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReadinessController extends Controller
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        $ready = false;
        try {
            DB::select('SELECT 1');
            $repository = $migrator->getRepository();
            $ready = $repository->repositoryExists()
                && array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $repository->getRan()) === [];
        } catch (\Throwable $exception) {
            // Public readiness must not disclose hosts, SQL, credentials or stack traces.
        }

        return response()->json(['ready' => $ready, 'release_sha' => config('deployment.release_sha')], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
