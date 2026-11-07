<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['role' => 'admin', 'company_id' => Company::create(['name' => 'A'])->id]);
        $this->actingAs($this->actor);
    }

    public function test_clients_reserve_normalized_email_per_company_including_archives(): void
    {
        $one = $this->postJson('/api/clients', ['name' => 'One', 'email' => ' SAME@example.test '])->assertCreated()->json();
        $this->assertSame('same@example.test', $one['email']);
        $this->postJson('/api/clients', ['name' => 'Two', 'email' => 'same@example.test'])->assertUnprocessable();
        $two = $this->postJson('/api/clients', ['name' => 'Two'])->assertCreated()->json();
        $this->patchJson('/api/clients/'.$two['id'], ['email' => 'SAME@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/clients/'.$one['id'], ['email' => 'same@example.test'])->assertOk();
        $this->deleteJson('/api/clients/'.$one['id'])->assertOk();
        $this->postJson('/api/clients', ['name' => 'Replacement', 'email' => 'same@example.test'])->assertUnprocessable();
        $this->patchJson('/api/clients/'.$one['id'].'/restore')->assertOk();
        $other = User::factory()->create(['company_id' => Company::create(['name' => 'B'])->id]);
        $this->actingAs($other)->postJson('/api/clients', ['name' => 'Other', 'email' => 'same@example.test'])->assertCreated();
        $this->getJson('/api/clients/'.$one['id'])->assertNotFound();
        $this->patchJson('/api/clients/'.$one['id'], ['name' => 'Attack'])->assertNotFound();
        $this->deleteJson('/api/clients/'.$one['id'])->assertNotFound();
    }

    public function test_client_null_emails_and_partial_updates_are_supported(): void
    {
        foreach (['', null] as $email) {
            $this->postJson('/api/clients', ['name' => 'No email', 'email' => $email])->assertCreated()->assertJsonPath('email', null);
        }
        $client = $this->postJson('/api/clients', ['name' => 'Original', 'phone' => '+212 (600) 123-456', 'tax_number' => 'AB/123-4'])->assertCreated()->json();
        $this->patchJson('/api/clients/'.$client['id'], ['name' => 'Changed'])->assertOk()->assertJsonPath('phone', '+212 (600) 123-456');
        $this->postJson('/api/clients', ['name' => 'Bad', 'phone' => 'letters', 'tax_number' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'tax_number']);
        $this->postJson('/api/clients', ['name' => 'Bad', 'company_id' => 999, 'email' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public static function invalidNumbers(): array
    {
        return [['unit_price', '-0.01'], ['unit_price', '10000000000'], ['unit_price', '1.001'], ['unit_price', '1e2'],
            ['unit_price', 'NaN'], ['unit_price', []], ['tax_rate', '100.01'], ['tax_rate', '-1'], ['tax_rate', '20.001'], ['tax_rate', '2e1']];
    }

    #[DataProvider('invalidNumbers')]
    public function test_product_numeric_validation_is_identical_on_create_and_update(string $field, mixed $value): void
    {
        $product = Product::create(['company_id' => $this->actor->company_id, 'name' => 'Keep', 'unit_price' => '10.00', 'tax_rate' => '20.00']);
        $this->postJson('/api/products', ['name' => 'Invalid', 'unit_price' => '10.00', $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/products/'.$product->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('10.00', $product->fresh()->unit_price);
        $this->assertSame('20.00', $product->fresh()->tax_rate);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_product_boundaries_null_tax_and_partial_updates(): void
    {
        $product = $this->postJson('/api/products', ['name' => 'Max', 'unit_price' => '9999999999.99', 'tax_rate' => null])->assertCreated()->json();
        $this->assertSame('0.00', $product['tax_rate']);
        $this->patchJson('/api/products/'.$product['id'], ['tax_rate' => '100'])->assertOk()->assertJsonPath('tax_rate', '100.00');
        $this->patchJson('/api/products/'.$product['id'], ['name' => 'Keep tax'])->assertOk()->assertJsonPath('tax_rate', '100.00');
        $this->patchJson('/api/products/'.$product['id'], ['tax_rate' => ''])->assertOk()->assertJsonPath('tax_rate', '0.00');
        $this->postJson('/api/products', ['name' => 'Zero', 'unit_price' => '0'])->assertCreated();
    }

    public function test_referenced_product_is_preserved_by_api_and_database(): void
    {
        $product = Product::create(['company_id' => $this->actor->company_id, 'name' => 'Used', 'unit_price' => '10']);
        $client = Client::create(['company_id' => $this->actor->company_id, 'name' => 'Buyer']);
        foreach (['quotes' => 'quote_date', 'invoices' => 'invoice_date'] as $resource => $date) {
            $document = $this->postJson('/api/'.$resource, ['client_id' => $client->id, $date => today()->toDateString(),
                'items' => [['product_id' => $product->id, 'description' => 'Keep', 'quantity' => '1', 'unit_price' => '10', 'tax_rate' => '20']]])->assertCreated()->json();
            $this->deleteJson('/api/products/'.$product->id)->assertConflict();
            $this->assertDatabaseHas($resource === 'quotes' ? 'quote_items' : 'invoice_items', ['product_id' => $product->id, 'total' => '12.00']);
        }
        try {
            DB::transaction(fn () => DB::table('products')->where('id', $product->id)->delete());
            $this->fail('FK must prevent deletion');
        } catch (QueryException $exception) {
            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    public static function invalidSettings(): array
    {
        return [['billing', 'payment_terms_days', null], ['billing', 'currency', null], ['billing', 'currency', 'BTC'],
            ['billing', 'default_tax_rate', '20.001'], ['documents', 'invoice_prefix', null], ['documents', 'invoice_prefix', '<bad>'],
            ['documents', 'invoice_prefix', "LINE\nBREAK"], ['documents', 'logo', 'javascript:alert(1)'], ['notifications', 'notify_on_payment', null]];
    }

    #[DataProvider('invalidSettings')]
    public function test_company_settings_reject_invalid_data_without_sql_errors(string $section, string $field, mixed $value): void
    {
        $before = $this->actor->company->getRawOriginal();
        $this->patchJson('/api/settings/'.$section, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->actor->company->fresh()->getRawOriginal());
    }

    public function test_empty_optional_tax_is_preserved_and_prefix_change_does_not_renumber(): void
    {
        $client = Client::create(['company_id' => $this->actor->company_id, 'name' => 'Buyer']);
        $payload = ['client_id' => $client->id, 'invoice_date' => today()->toDateString(), 'items' => [['description' => 'Keep', 'quantity' => 1, 'unit_price' => 10]]];
        $one = $this->postJson('/api/invoices', $payload)->assertCreated()->json();
        $this->patchJson('/api/settings/billing', ['default_tax_rate' => null, 'payment_terms_days' => 0])->assertOk()->assertJsonPath('company.default_tax_rate', null);
        $this->patchJson('/api/settings/documents', ['invoice_prefix' => 'NEW_'])->assertOk();
        $two = $this->postJson('/api/invoices', $payload)->assertCreated()->json();
        $this->assertStringEndsWith('-002', $two['invoice_number']);
        $this->assertStringStartsWith('NEW_', $two['invoice_number']);
        $this->assertDatabaseHas('invoices', ['id' => $one['id'], 'invoice_number' => $one['invoice_number']]);
    }
}
