<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumStatefulMiddleware;

/**
 * Keep Sanctum's SPA authentication pipeline while honoring the configured
 * session SameSite value (e.g. "none" for cross-site cookie testing).
 */
class EnsureFrontendRequestsAreStateful extends SanctumStatefulMiddleware
{
    protected function configureSecureCookieSessions()
    {
        // Sanctum defaults to SameSite=Lax here, overriding config/session.php.
        // Keep cookies HttpOnly without overwriting the application's SameSite
        // or Secure settings.
        config(['session.http_only' => true]);
    }
}
