<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // Runs after auth:sanctum on every authenticated API route, including /api/user.
        // Re-read current DB state: a disabled account must not retain API access via
        // an already-issued Sanctum bearer token or a cached User instance.
        $user = $request->user();
        if (! $user || ! $user->newQuery()->whereKey($user->getKey())->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Ce compte est désactivé. Contactez votre administrateur.',
                'code' => 'ACCOUNT_DISABLED',
            ], 401);
        }

        return $next($request);
    }
}
