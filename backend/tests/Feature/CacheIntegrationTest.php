<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CompanyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CacheIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_lists_are_cached_and_invalidated_after_changes(): void
    {
        $account = $this->registerUser('cache@example.com', 'Cache PME');
        $user = User::findOrFail($account['user']['id']);
        $companyId = $account['user']['company_id'];

        $this->actingAs($user)->getJson('/api/clients')->assertOk()->assertJsonCount(0);
        $this->assertTrue(Cache::has(CompanyCache::key($companyId, CompanyCache::CLIENTS)));

        $client = $this->actingAs($user)->postJson('/api/clients', [
            'name' => 'Client cache',
            'email' => 'client-cache@example.com',
        ])->assertCreated()->json();

        $this->actingAs($user)->getJson('/api/clients')->assertJsonCount(1);
        $this->actingAs($user)->putJson("/api/clients/{$client['id']}", [
            'name' => 'Client modifié',
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/clients')
            ->assertJsonPath('0.name', 'Client modifié');

        $this->actingAs($user)->deleteJson("/api/clients/{$client['id']}")->assertOk();
        $this->actingAs($user)->getJson('/api/clients')->assertJsonCount(0);

        $this->actingAs($user)->getJson('/api/products')->assertOk()->assertJsonCount(0);
        $product = $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Produit cache',
            'unit_price' => 10,
            'tax_rate' => 20,
        ])->assertCreated()->json();
        $this->actingAs($user)->getJson('/api/products')->assertJsonCount(1);
        $this->actingAs($user)->deleteJson("/api/products/{$product['id']}")->assertOk();
        $this->actingAs($user)->getJson('/api/products')->assertJsonCount(0);
    }

    public function test_cache_keys_are_isolated_between_companies(): void
    {
        $first = $this->registerUser('first@example.com', 'Première PME');
        $second = $this->registerUser('second@example.com', 'Seconde PME');
        $this->assertNotSame($first['user']['company_id'], $second['user']['company_id']);

        $this->actingAs(User::findOrFail($first['user']['id']))->postJson('/api/clients', [
            'name' => 'Client première',
            'email' => 'first-client@example.com',
        ])->assertCreated();
        $this->actingAs(User::findOrFail($second['user']['id']))->postJson('/api/clients', [
            'name' => 'Client seconde',
            'email' => 'second-client@example.com',
        ])->assertCreated();

        $this->actingAs(User::findOrFail($first['user']['id']))->getJson('/api/clients')
            ->assertJsonCount(1)
            ->assertJsonPath('0.company_id', $first['user']['company_id'])
            ->assertJsonPath('0.name', 'Client première');
        $this->actingAs(User::findOrFail($second['user']['id']))->getJson('/api/clients')
            ->assertJsonCount(1)
            ->assertJsonPath('0.company_id', $second['user']['company_id'])
            ->assertJsonPath('0.name', 'Client seconde');
    }

    public function test_invoice_quote_and_payment_changes_invalidate_cached_lists(): void
    {
        $account = $this->registerUser('documents@example.com', 'Documents PME');
        $user = User::findOrFail($account['user']['id']);

        $client = $this->actingAs($user)->postJson('/api/clients', [
            'name' => 'Client documents',
            'email' => 'documents-client@example.com',
        ])->assertCreated()->json();

        $invoice = $this->actingAs($user)->postJson('/api/invoices', [
            'client_id' => $client['id'],
            'invoice_date' => '2026-10-31',
            'items' => [[
                'description' => 'Prestation',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ])->assertCreated()->json();

        $this->actingAs($user)->getJson('/api/invoices')->assertJsonCount(1);
        $this->actingAs($user)->patchJson("/api/invoices/{$invoice['id']}/status", [
            'status' => 'sent',
        ])->assertOk();
        $this->actingAs($user)->postJson("/api/invoices/{$invoice['id']}/payments", [
            'amount' => 10,
            'payment_date' => '2026-10-31',
            'payment_method' => 'cash',
        ])->assertCreated();
        $this->actingAs($user)->getJson('/api/invoices')
            ->assertJsonPath('0.status', 'paid');

        $quote = $this->actingAs($user)->postJson('/api/quotes', [
            'client_id' => $client['id'],
            'quote_date' => '2026-10-31',
            'valid_until' => '2026-11-30',
            'items' => [[
                'description' => 'Conseil',
                'quantity' => 1,
                'unit_price' => 20,
                'tax_rate' => 0,
            ]],
        ])->assertCreated()->json();

        $this->actingAs($user)->getJson('/api/quotes')->assertJsonCount(1);
        $this->actingAs($user)->patchJson("/api/quotes/{$quote['id']}/status", [
            'status' => 'sent',
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/quotes')
            ->assertJsonPath('0.status', 'sent');
    }

    private function registerUser(string $email, string $companyName): array
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => $email,
            'company_name' => $companyName,
            'password' => 'Secret1234! PME',
            'password_confirmation' => 'Secret1234! PME',
        ]);

        $response->assertCreated();

        return $response->json();
    }
}
