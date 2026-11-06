<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyReferenceValidationTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private Client $foreignClient;
    private Product $product;
    private Product $foreignProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Company A']);
        $other = Company::create(['name' => 'Company B']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'company_id' => $company->id]));
        $this->client = Client::create(['company_id' => $company->id, 'name' => 'Client A']);
        $this->foreignClient = Client::create(['company_id' => $other->id, 'name' => 'Client B']);
        $this->product = Product::create(['company_id' => $company->id, 'name' => 'Product A', 'unit_price' => 10]);
        $this->foreignProduct = Product::create(['company_id' => $other->id, 'name' => 'Product B', 'unit_price' => 10]);
    }

    public function test_documents_reject_foreign_references_without_changing_data(): void
    {
        foreach (['invoices' => 'invoice_date', 'quotes' => 'quote_date'] as $resource => $date) {
            $payload = $this->payload($date);
            $foreignClient = array_replace($payload, ['client_id' => $this->foreignClient->id, 'company_id' => $this->foreignClient->company_id]);
            $foreignProduct = $payload;
            $foreignProduct['items'][0]['product_id'] = $this->foreignProduct->id;

            $this->postJson("/api/$resource", $foreignClient)->assertUnprocessable()->assertJsonValidationErrors('client_id');
            $this->postJson("/api/$resource", $foreignProduct)->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
            $this->assertDatabaseCount($resource, 0);

            $document = $this->postJson("/api/$resource", $payload)->assertCreated()->json();
            $url = "/api/$resource/{$document['id']}";
            $this->patchJson($url, ['client_id' => $this->foreignClient->id])->assertUnprocessable()->assertJsonValidationErrors('client_id');
            $this->patchJson($url, ['items' => $foreignProduct['items']])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
            $this->getJson($url)->assertOk()
                ->assertJsonPath('client_id', $this->client->id)
                ->assertJsonPath('items.0.product_id', $this->product->id);
        }
    }

    public function test_documents_accept_own_references_and_optional_products(): void
    {
        foreach (['invoices' => 'invoice_date', 'quotes' => 'quote_date'] as $resource => $date) {
            $payload = $this->payload($date);
            $document = $this->postJson("/api/$resource", $payload)->assertCreated()->json();
            $url = "/api/$resource/{$document['id']}";
            $this->patchJson($url, $payload)->assertOk();
            $payload['items'][0]['product_id'] = null;
            $this->patchJson($url, ['items' => $payload['items']])->assertOk()->assertJsonPath('items.0.product_id', null);
            unset($payload['items'][0]['product_id']);
            $this->postJson("/api/$resource", $payload)->assertCreated()->assertJsonPath('items.0.product_id', null);
            $payload['items'][0]['product_id'] = null;
            $this->postJson("/api/$resource", $payload)->assertCreated()->assertJsonPath('items.0.product_id', null);
        }
    }

    public function test_quote_items_validate_company_on_creation_and_update(): void
    {
        $quote = $this->postJson('/api/quotes', $this->payload('quote_date'))->assertCreated()->json();
        $url = "/api/quotes/{$quote['id']}/items";
        $item = $this->payload('quote_date')['items'][0];
        $foreign = array_replace($item, ['product_id' => $this->foreignProduct->id]);
        $this->postJson($url, $foreign)->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('quote_items', 1);

        $created = $this->postJson($url, $item)->assertCreated()->json();
        $itemUrl = "$url/{$created['id']}";
        $this->patchJson($itemUrl, $foreign)->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseHas('quote_items', ['id' => $created['id'], 'product_id' => $this->product->id]);
        $this->patchJson($itemUrl, ['product_id' => $this->product->id])->assertOk();
        $this->patchJson($itemUrl, ['description' => 'Updated'])->assertOk()->assertJsonPath('product_id', $this->product->id);
        $this->patchJson($itemUrl, ['product_id' => null])->assertOk()->assertJsonPath('product_id', null);
        $item['product_id'] = null;
        $this->postJson($url, $item)->assertCreated()->assertJsonPath('product_id', null);
        unset($item['product_id']);
        $this->postJson($url, $item)->assertCreated()->assertJsonPath('product_id', null);
    }

    private function payload(string $date): array
    {
        return [
            'client_id' => $this->client->id,
            $date => '2026-11-03',
            'items' => [[
                'product_id' => $this->product->id,
                'description' => 'Service',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ];
    }
}
