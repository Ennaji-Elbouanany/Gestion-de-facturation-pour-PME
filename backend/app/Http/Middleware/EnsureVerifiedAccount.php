<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('accounts.require_verified_email') && ! $request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Vérifiez votre adresse e-mail pour accéder à cette fonctionnalité.', 'code' => 'EMAIL_UNVERIFIED'], 403);
        }

        return $next($request);
    }
}
