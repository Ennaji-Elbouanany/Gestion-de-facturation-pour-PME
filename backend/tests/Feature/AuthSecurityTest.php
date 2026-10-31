<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'TEST@example.com',
            'company_name' => 'Test PME',
            'password' => 'azerty',
            'password_confirmation' => 'azerty',
            'role' => 'admin',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_normalizes_email(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => '  TEST@example.com ',
            'company_name' => 'Test PME',
            'password' => 'Maison!Bleue2026',
            'password_confirmation' => 'Maison!Bleue2026',
            'role' => 'admin',
        ]);

        $response->assertCreated();
        $this->assertSame('test@example.com', $response->json('user.email'));
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

    public function test_login_creates_a_session_without_returning_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => bcrypt('Maison!Bleue2026'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Maison!Bleue2026',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'password' => bcrypt('Maison!Bleue2026'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Maison!Bleue2026',
        ])->assertOk();
        $this->postJson('/api/logout')
            ->assertOk();

        $this->assertNull(session(Auth::guard('web')->getName()));
    }
}
