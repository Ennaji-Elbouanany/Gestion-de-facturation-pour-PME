<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'TEST@example.com',
            'company_name' => 'Test PME',
            'password' => 'azerty',
            'password_confirmation' => 'azerty',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_normalizes_email_and_returns_bearer_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => '  TEST@example.com ',
            'company_name' => 'Test PME',
            'password' => 'Maison!Bleue2026',
            'password_confirmation' => 'Maison!Bleue2026',
            'role' => 'admin',
        ]);

        $response->assertCreated()->assertJsonPath('user.email', 'test@example.com');
        $this->assertNotEmpty($response->json('token'));

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'test@example.com');

        $this->withToken($response->json('token'))
            ->getJson('/api/dashboard/summary')
            ->assertOk();
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = [
            'email' => 'unknown@example.com',
            'password' => 'WrongPassword!2026',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', $payload)->assertUnauthorized();
        }

        $this->postJson('/api/login', $payload)->assertStatus(429);
    }

    public function test_login_returns_token_and_allows_protected_endpoints(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => bcrypt('Maison!Bleue2026'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Maison!Bleue2026',
        ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertNotEmpty($response->json('token'));

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        $this->withToken($response->json('token'))
            ->getJson('/api/clients')
            ->assertOk();
    }

    public function test_protected_api_rejects_missing_or_invalid_token(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->withToken('invalid-token')->getJson('/api/dashboard/summary')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => bcrypt('Maison!Bleue2026'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Maison!Bleue2026',
        ])->assertOk();

        $token = $response->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }
}
