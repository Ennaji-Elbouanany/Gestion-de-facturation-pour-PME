<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_requires_authentication(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
        ])->get('/api/settings');

        $response->assertStatus(401);
    }

    public function test_user_can_load_settings(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->get('/api/settings');

        $response->assertSuccessful();
        $response->assertJsonPath('user.name', 'Dupont');
        $response->assertJsonPath('company.name', 'Ma PME');
        $response->assertJsonPath('company.payment_terms_days', 30);
        $response->assertJsonPath('company.currency', 'MAD');
        $response->assertJsonPath('company.invoice_prefix', 'FAC-');

        $company = $response->json('company');
        $this->assertTrue($company['notify_on_new_invoice'] == true);
    }

    public function test_user_can_update_company_settings(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->patchJson('/api/settings/company', [
            'name' => 'Nouvelle PME',
            'ice' => '0012345678900',
            'tax_number' => '4567890',
            'address' => '12 rue de la Paix',
            'city' => 'Casablanca',
            'country' => 'Maroc',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('company.name', 'Nouvelle PME');
        $response->assertJsonPath('company.ice', '0012345678900');
        $response->assertJsonPath('company.tax_number', '4567890');
        $response->assertJsonPath('company.city', 'Casablanca');
    }

    public function test_user_can_update_billing_and_documents(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $billing = $this->actingAs($user)->patchJson('/api/settings/billing', [
            'payment_terms_days' => 45,
            'default_tax_rate' => 20,
            'currency' => 'EUR',
        ]);

        $billing->assertSuccessful();
        $billing->assertJsonPath('company.payment_terms_days', 45);
        $billing->assertJsonPath('company.currency', 'EUR');

        $documents = $this->actingAs($user)->patchJson('/api/settings/documents', [
            'invoice_prefix' => 'DEV-',
            'invoice_footer' => 'Merci de votre confiance.',
        ]);

        $documents->assertSuccessful();
        $documents->assertJsonPath('company.invoice_prefix', 'DEV-');
        $documents->assertJsonPath('company.invoice_footer', 'Merci de votre confiance.');
    }

    public function test_user_can_update_notifications(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->patchJson('/api/settings/notifications', [
            'notify_on_new_invoice' => false,
            'notify_on_payment' => true,
        ]);

        $response->assertSuccessful();

        $company = $response->json('company');
        $this->assertTrue($company['notify_on_new_invoice'] == false);
        $this->assertTrue($company['notify_on_payment'] == true);
        $this->assertTrue($company['notify_on_quote'] == true);
    }

    public function test_user_can_update_profile(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->patchJson('/api/settings/profile', [
            'name' => 'Nouveau Nom',
            'email' => 'nouveau@example.com',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('user.name', 'Nouveau Nom');
        $response->assertJsonPath('user.email', 'nouveau@example.com');
    }

    public function test_user_can_change_password(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->patchJson('/api/settings/security', [
            'current_password' => 'Secret1234! PME',
            'password' => 'Nouveau1234! Mot',
            'password_confirmation' => 'Nouveau1234! Mot',
        ]);

        $response->assertSuccessful();

        $user = User::where('email', 'dupont@example.com')->first();
        $this->assertTrue(Hash::check('Nouveau1234! Mot', $user->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $account = $this->registerUser();
        $user = User::findOrFail($account['user']['id']);

        $response = $this->actingAs($user)->patchJson('/api/settings/security', [
            'current_password' => 'mauvais-mot-de-passe',
            'password' => 'Nouveau1234! Mot',
            'password_confirmation' => 'Nouveau1234! Mot',
        ]);

        $response->assertStatus(422);
    }

    private function registerUser(): array
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Dupont',
            'email' => 'dupont@example.com',
            'company_name' => 'Ma PME',
            'password' => 'Secret1234! PME',
            'password_confirmation' => 'Secret1234! PME',
            'role' => 'admin',
        ]);

        $response->assertStatus(201);

        return $response->json();
    }
}