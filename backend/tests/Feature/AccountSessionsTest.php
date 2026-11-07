<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin'): User
    {
        return User::factory()->create(['company_id' => Company::create(['name' => 'Test'])->id,
            'role' => $role, 'password' => Hash::make('OriginalSecret!2026')]);
    }

    private function bearer(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public static function roles(): array
    {
        return [['admin'], ['employee'], ['comptable']];
    }

    #[DataProvider('roles')]
    public function test_password_change_revokes_only_the_accounts_sessions(string $role): void
    {
        $user = $this->account($role);
        $one = $user->createToken('one')->plainTextToken;
        $two = $user->createToken('two')->plainTextToken;
        $other = $this->account()->createToken('other')->plainTextToken;
        $this->bearer($one)->patchJson('/api/settings/security', ['current_password' => 'OriginalSecret!2026',
            'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'])
            ->assertOk()->assertJsonPath('reauthenticate', true);
        $this->assertTrue(Hash::check('ReplacementSecret!2026', $user->fresh()->password));
        $this->assertNull(PersonalAccessToken::findToken($one));
        $this->assertNull(PersonalAccessToken::findToken($two));
        $this->bearer($two)->getJson('/api/user')->assertUnauthorized();
        $this->bearer($other)->getJson('/api/user')->assertOk();
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OriginalSecret!2026'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ReplacementSecret!2026'])->assertOk();
    }

    public function test_wrong_password_changes_neither_hash_nor_tokens(): void
    {
        $user = $this->account();
        $token = $user->createToken('one')->plainTextToken;
        $hash = $user->password;
        $this->bearer($token)->patchJson('/api/settings/security', ['current_password' => 'wrong',
            'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_revocation_failure_rolls_back_password_and_remember_token(): void
    {
        $user = $this->account();
        $token = $user->createToken('one')->plainTextToken;
        $before = $user->getRawOriginal();
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'delete from') && str_contains($query->sql, 'personal_access_tokens')) {
                throw new \RuntimeException('Simulated revocation failure');
            }
        });
        $this->bearer($token)->patchJson('/api/settings/security', ['current_password' => 'OriginalSecret!2026',
            'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'])->assertStatus(500);
        $this->assertSame($before['password'], $user->fresh()->password);
        $this->assertSame($before['remember_token'], $user->fresh()->remember_token);
        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_real_token_expiration_and_logout_are_session_specific(): void
    {
        $user = $this->account();
        $login = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OriginalSecret!2026'])->assertOk();
        $one = $login->json('token');
        $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, PersonalAccessToken::findToken($one)->expires_at->timestamp, 2);
        $two = $user->createToken('two', ['*'], now()->addDay())->plainTextToken;
        $this->bearer($one)->postJson('/api/logout')->assertOk();
        $this->bearer($one)->getJson('/api/user')->assertUnauthorized();
        $this->bearer($two)->getJson('/api/user')->assertOk();
        $this->travel(25)->hours();
        $this->bearer($two)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_reset_revokes_all_sessions_and_reset_link_is_single_use(): void
    {
        $user = $this->account();
        $one = $user->createToken('one')->plainTextToken;
        $two = $user->createToken('two')->plainTextToken;
        $other = $this->account()->createToken('other')->plainTextToken;
        $reset = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $reset, 'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'];
        $this->postJson('/api/reset-password', $data)->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($one));
        $this->assertNull(PersonalAccessToken::findToken($two));
        $this->assertNotNull(PersonalAccessToken::findToken($other));
        $this->postJson('/api/reset-password', $data)->assertUnprocessable();
    }

    public function test_reset_rejects_expired_link_without_changing_password(): void
    {
        $user = $this->account();
        $reset = Password::createToken($user);
        $hash = $user->password;
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $reset,
            'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'])->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_email_change_requires_password_clears_verification_and_invalidates_old_link(): void
    {
        Notification::fake();
        $user = $this->account();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->patchJson('/api/settings/profile', ['name' => 'New', 'email' => 'changed@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/settings/profile', ['name' => 'New', 'email' => ' CHANGED@example.test ', 'current_password' => 'OriginalSecret!2026'])
            ->assertOk()->assertJsonPath('user.email', 'changed@example.test')->assertJsonPath('user.email_verified_at', null);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get($url)->assertForbidden();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinute(), ['id' => $user->id, 'hash' => sha1('changed@example.test')]);
        $this->get($url.'tampered')->assertForbidden();
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_name_only_profile_update_preserves_verification(): void
    {
        Notification::fake();
        $user = $this->account();
        $verified = $user->email_verified_at;
        $this->actingAs($user)->patchJson('/api/settings/profile', ['name' => 'New', 'email' => $user->email])->assertOk();
        $this->assertEquals($verified, $user->fresh()->email_verified_at);
        Notification::assertNothingSent();
    }

    public function test_email_change_cannot_take_another_users_address(): void
    {
        $user = $this->account();
        $other = $this->account();
        $this->actingAs($user)->patchJson('/api/settings/profile', ['name' => 'New', 'email' => $other->email, 'current_password' => 'OriginalSecret!2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotSame($other->email, $user->fresh()->email);
    }

    public function test_optional_verification_gate_preserves_recovery_routes_and_permissions(): void
    {
        $user = $this->account('employee');
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->getJson('/api/clients')->assertOk();
        config(['accounts.require_verified_email' => true]);
        $this->getJson('/api/clients')->assertForbidden()->assertJsonPath('code', 'EMAIL_UNVERIFIED');
        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/settings')->assertOk();
        $this->patchJson('/api/settings/company', ['name' => 'Forbidden'])->assertForbidden();
        $user->markEmailAsVerified();
        $this->getJson('/api/clients')->assertOk();
    }

    public function test_verification_resend_is_authenticated_and_rate_limited(): void
    {
        Notification::fake();
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
        $user = $this->account();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/email/verification-notification')->assertOk();
        }
        $this->postJson('/api/email/verification-notification')->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }

    public function test_forgot_password_has_same_response_for_unknown_and_known_accounts(): void
    {
        Notification::fake();
        $user = $this->account();
        $one = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->json();
        $two = $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($one, $two);
    }
}
