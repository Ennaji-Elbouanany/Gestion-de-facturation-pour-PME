<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFrontendRequestsAreStateful as AppStatefulMiddleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumStatefulMiddleware;
use Tests\TestCase;

class StatefulSessionCookieTest extends TestCase
{
    public function test_api_group_uses_the_application_stateful_middleware(): void
    {
        $apiMiddleware = app('router')->getMiddlewareGroups()['api'];

        $this->assertContains(AppStatefulMiddleware::class, $apiMiddleware);
        $this->assertNotContains(SanctumStatefulMiddleware::class, $apiMiddleware);
    }

    public function test_custom_stateful_middleware_does_not_override_same_site_or_secure(): void
    {
        config()->set('session.same_site', 'none');
        config()->set('session.secure', true);
        config()->set('session.http_only', false);

        $middleware = new AppStatefulMiddleware();
        $response = $middleware->handle(
            Request::create('/api/user', 'GET'),
            fn ($request) => response()->json(['ok' => true])
        );

        $this->assertTrue($response->isOk());
        $this->assertSame('none', config('session.same_site'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
    }
}
