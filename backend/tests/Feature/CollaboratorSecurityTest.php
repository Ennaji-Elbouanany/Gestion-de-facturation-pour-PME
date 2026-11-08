<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollaboratorSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['admin'], ['employee'], ['comptable']];
    }

    private function account(string $role, string $companyName): User
    {
        $company = Company::create(['name' => $companyName]);

        return User::factory()->create([
            'company_id' => $company->id,
            'role' => $role,
            'password' => Hash::make('OriginalSecret!2026'),
        ]);
    }

    private function bearer(string $token): static
    {
        // Tests reuse the application instance, unlike distinct HTTP requests.
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public function test_migration_adds_active_default_without_changing_existing_roles(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_active'));
        $company = Company::create(['name' => 'Already registered']);
        $user = User::factory()->create(['company_id' => $company->id, 'role' => 'comptable']);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame('comptable', $user->fresh()->role);
        $this->assertSame($company->id, $user->fresh()->company_id);
    }

    #[DataProvider('roles')]
    public function test_active_users_keep_login_and_access_for_all_three_roles(string $role): void
    {
        $user = $this->account($role, 'Active tenant '.$role);
        $this->assertTrue($user->is_active);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'OriginalSecret!2026',
        ])->assertOk();
        $this->bearer($login->json('token'))->getJson('/api/user')
            ->assertOk()->assertJsonPath('role', $role)->assertJsonPath('is_active', true);
        $this->bearer($login->json('token'))->getJson('/api/clients')->assertOk();
    }

    #[DataProvider('roles')]
    public function test_disabled_users_cannot_log_in_or_use_existing_tokens(string $role): void
    {
        $user = $this->account($role, 'Disabled tenant '.$role);
        $oldToken = $user->createToken('old')->plainTextToken;

        // Simulates a state change before token cleanup: middleware must still deny it.
        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/login', ['email' => $user->email,
            'password' => 'OriginalSecret!2026'])->assertUnauthorized();

        foreach (['/api/user', '/api/settings', '/api/dashboard/summary', '/api/clients'] as $path) {
            $this->bearer($oldToken)->getJson($path)
                ->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_DISABLED');
        }

        $this->bearer($oldToken)->postJson('/api/logout')
            ->assertUnauthorized()->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_deactivation_revokes_all_tokens_but_preserves_company_and_financial_relationships(): void
    {
        $user = $this->account('employee', 'Company protected');
        $other = $this->account('comptable', 'Independent company');
        $client = Client::create(['company_id' => $user->company_id, 'name' => 'Existing client']);
        $tokenOne = $user->createToken('device-one')->plainTextToken;
        $tokenTwo = $user->createToken('device-two')->plainTextToken;
        $otherToken = $other->createToken('unaffected')->plainTextToken;

        app(AccountActivationService::class)->deactivate($user->id);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('employee', $user->fresh()->role);
        $this->assertSame($client->company_id, $user->fresh()->company_id);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'company_id' => $client->company_id]);
        $this->assertNull(PersonalAccessToken::findToken($tokenOne));
        $this->assertNull(PersonalAccessToken::findToken($tokenTwo));
        $this->assertNotNull(PersonalAccessToken::findToken($otherToken));
        $this->bearer($tokenOne)->getJson('/api/user')->assertUnauthorized();
        $this->bearer($otherToken)->getJson('/api/user')->assertOk();
    }

    public function test_new_registration_still_creates_active_company_administrator(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Founder', 'email' => 'founder.active@example.test',
            'company_name' => 'Registered company',
            'password' => 'FounderSecret!2026',
            'password_confirmation' => 'FounderSecret!2026',
        ])->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonPath('user.is_active', true);
    }

    public function test_disabled_account_is_not_allowed_to_access_another_tenant(): void
    {
        $actor = $this->account('admin', 'Tenant A');
        $target = $this->account('admin', 'Tenant B');
        $token = $actor->createToken('before-disable')->plainTextToken;
        Client::create(['company_id' => $target->company_id, 'name' => 'Private B']);

        $actor->forceFill(['is_active' => false])->save();
        $this->bearer($token)->getJson('/api/clients')->assertUnauthorized();
    }
}
