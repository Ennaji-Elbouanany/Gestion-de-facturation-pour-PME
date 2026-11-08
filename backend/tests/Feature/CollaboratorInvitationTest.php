<?php

namespace Tests\Feature;

use App\Mail\CollaboratorInvitationMail;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CollaboratorInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('app.frontend_url', 'https://factura.example.test');
    }

    private function account(string $role, string $name): User
    {
        $company = Company::create(['name' => $name]);

        return User::factory()->create(['company_id' => $company->id, 'role' => $role]);
    }

    private function tokenFromMail(): string
    {
        Mail::assertSent(CollaboratorInvitationMail::class, 1);
        $mail = Mail::sent(CollaboratorInvitationMail::class)->first();
        preg_match('/[?&]token=([a-zA-Z0-9]{64})/', $mail->inviteUrl, $matches);
        $this->assertCount(2, $matches);

        return $matches[1];
    }

    public function test_admin_invites_accountant_and_acceptance_creates_user_in_same_company(): void
    {
        $admin = $this->account('admin', 'Company A');
        $this->actingAs($admin)->postJson('/api/invitations', [
            'email' => ' ACCOUNTANT@example.test ', 'role' => 'comptable',
        ])->assertCreated()->assertJsonPath('invitation.email', 'accountant@example.test');
        $token = $this->tokenFromMail();
        $this->assertDatabaseCount('users', 1);
        $this->assertNotEquals($token, UserInvitation::firstOrFail()->token_hash);
        $this->getJson('/api/invitations/validate?token='.$token)->assertOk()
            ->assertJsonPath('company_name', 'Company A')
            ->assertJsonPath('role', 'comptable');

        $response = $this->postJson('/api/invitations/accept', [
            'token' => $token, 'name' => 'My accountant',
            'password' => 'StrongSecret!2026',
            'password_confirmation' => 'StrongSecret!2026',
        ])->assertCreated()->assertJsonPath('user.role', 'comptable')
            ->assertJsonPath('user.company_id', $admin->company_id);

        $user = User::findOrFail($response->json('user.id'));
        $this->assertSame('accountant@example.test', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('StrongSecret!2026', $user->password));
        $this->assertNotNull(UserInvitation::firstOrFail()->accepted_at);
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'StrongSecret!2026'])->assertOk();
        $this->postJson('/api/invitations/accept', ['token' => $token, 'name' => 'Replay',
            'password' => 'StrongSecret!2026', 'password_confirmation' => 'StrongSecret!2026'])->assertUnprocessable();
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_admin_roles_cannot_manage_invitations(string $role): void
    {
        $actor = $this->account($role, 'Restricted');
        $this->actingAs($actor);
        $this->getJson('/api/invitations')->assertForbidden();
        $this->postJson('/api/invitations', ['email' => 'new@example.test', 'role' => 'employee'])->assertForbidden();
        $this->deleteJson('/api/invitations/999')->assertForbidden();
        Mail::assertNothingSent();
    }

    public static function unauthorizedRoles(): array
    {
        return [['employee'], ['comptable']];
    }

    public function test_foreign_admin_cannot_cancel_another_company_invitation(): void
    {
        $adminA = $this->account('admin', 'A');
        $adminB = $this->account('admin', 'B');
        $this->actingAs($adminA)->postJson('/api/invitations', [
            'email' => 'employee@example.test', 'role' => 'employee',
        ])->assertCreated();
        $invite = UserInvitation::firstOrFail();
        $this->actingAs($adminB)->deleteJson('/api/invitations/'.$invite->id)->assertNotFound();
        $this->assertDatabaseHas('user_invitations', ['id' => $invite->id]);
    }

    public function test_expired_and_cancelled_links_cannot_create_accounts(): void
    {
        $admin = $this->account('admin', 'Expiring company');
        $this->actingAs($admin)->postJson('/api/invitations', [
            'email' => 'first@example.test', 'role' => 'employee',
        ])->assertCreated();
        $old = $this->tokenFromMail();
        $this->travel(49)->hours();
        $this->getJson('/api/invitations/validate?token='.$old)->assertUnprocessable();
        $this->postJson('/api/invitations/accept', ['token' => $old, 'name' => 'Expired',
            'password' => 'StrongSecret!2026', 'password_confirmation' => 'StrongSecret!2026'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_new_invitation_revokes_previous_unused_link_and_cancel_revokes_current(): void
    {
        $admin = $this->account('admin', 'Revocation');
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'employee@example.test', 'role' => 'employee'])
            ->assertCreated();
        $first = $this->tokenFromMail();
        Mail::fake();
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'employee@example.test', 'role' => 'comptable'])
            ->assertCreated();
        $second = $this->tokenFromMail();
        $this->getJson('/api/invitations/validate?token='.$first)->assertUnprocessable();
        $this->getJson('/api/invitations/validate?token='.$second)->assertOk();
        $invite = UserInvitation::firstOrFail();
        $this->actingAs($admin)->deleteJson('/api/invitations/'.$invite->id)->assertOk();
        $this->getJson('/api/invitations/validate?token='.$second)->assertUnprocessable();
    }

    public function test_role_and_tenant_cannot_be_forged_during_invitation_or_acceptance(): void
    {
        $admin = $this->account('admin', 'Trust boundary');
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'new@example.test', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'new@example.test',
            'role' => 'employee', 'company_id' => 777])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->actingAs($admin)->postJson('/api/invitations', ['email' => 'new@example.test',
            'role' => 'employee'])->assertCreated();
        $token = $this->tokenFromMail();
        $this->postJson('/api/invitations/accept', [
            'token' => $token, 'name' => 'New', 'email' => 'forged@example.test',
            'role' => 'admin', 'company_id' => 777,
            'password' => 'StrongSecret!2026', 'password_confirmation' => 'StrongSecret!2026',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_registered_email_is_rejected(): void
    {
        $admin = $this->account('admin', 'Duplicate');
        $this->actingAs($admin)->postJson('/api/invitations', [
            'email' => $admin->email, 'role' => 'employee',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }
}
