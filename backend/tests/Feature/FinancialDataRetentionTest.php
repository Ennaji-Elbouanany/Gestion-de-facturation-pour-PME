<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class FinancialDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Retention PME']);
        $this->owner = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($this->owner);
        $this->client = Client::create(['company_id' => $company->id, 'name' => 'Historical client']);
    }

    private function document(string $resource, string $status = 'draft'): array
    {
        return $this->postJson('/api/'.$resource, [
            'client_id' => $this->client->id,
            $resource === 'invoices' ? 'invoice_date' : 'quote_date' => '2026-11-06',
            'status' => $status,
            'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20]],
        ])->assertCreated()->json();
    }

    private function payment(array $invoice): array
    {
        return $this->postJson("/api/invoices/{$invoice['id']}/payments", [
            'amount' => 50, 'payment_date' => '2026-11-06', 'payment_method' => 'cash',
        ])->assertCreated()->json();
    }

    public function test_client_archive_preserves_relations_statistics_cache_and_restores(): void
    {
        $invoice = $this->document('invoices');
        $quote = $this->document('quotes');
        $payment = $this->payment($invoice);
        foreach (['clients', 'invoices', 'quotes', 'dashboard/summary'] as $resource) {
            $this->getJson('/api/'.$resource)->assertOk();
        }
        $this->deleteJson('/api/clients/'.$this->client->id)->assertOk();
        $archiveDate = Client::withTrashed()->findOrFail($this->client->id)->deleted_at;
        $this->travel(1)->minutes();
        $this->deleteJson('/api/clients/'.$this->client->id)->assertOk();
        $this->assertTrue($archiveDate->equalTo(Client::withTrashed()->findOrFail($this->client->id)->deleted_at));
        $this->travelBack();
        $this->assertSoftDeleted('clients', ['id' => $this->client->id]);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('quote_items', 1);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'amount' => 50]);
        $this->getJson('/api/clients')->assertExactJson([]);
        $this->getJson('/api/clients?archived=1')->assertJsonPath('total', 1);
        $this->getJson('/api/invoices')->assertJsonPath('0.client.name', 'Historical client');
        $this->getJson('/api/quotes')->assertJsonPath('0.client.name', 'Historical client');
        $this->getJson("/api/invoices/{$invoice['id']}")->assertJsonPath('client.name', 'Historical client');
        $this->getJson("/api/quotes/{$quote['id']}")->assertJsonPath('client.name', 'Historical client');
        $this->getJson('/api/payments')->assertJsonPath('0.invoice.client.name', 'Historical client');
        $this->getJson('/api/dashboard/summary')->assertJsonPath('clients_count', 0)
            ->assertJsonPath('invoices_count', 1)->assertJsonPath('quotes_count', 1)->assertJsonPath('payments_count', 1);
        $this->patchJson('/api/clients/'.$this->client->id.'/restore')->assertOk();
        $this->patchJson('/api/clients/'.$this->client->id.'/restore')->assertOk();
        $this->getJson('/api/clients')->assertJsonCount(1);
        $this->getJson('/api/dashboard/summary')->assertJsonPath('clients_count', 1);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_archived_clients_cannot_be_assigned_except_to_their_existing_documents(): void
    {
        $documents = ['invoices' => $this->document('invoices'), 'quotes' => $this->document('quotes')];
        $this->deleteJson('/api/clients/'.$this->client->id)->assertOk();
        $other = Client::create(['company_id' => $this->owner->company_id, 'name' => 'Other']);
        $other->delete();
        foreach ($documents as $resource => $document) {
            $this->postJson('/api/'.$resource, ['client_id' => $this->client->id])
                ->assertUnprocessable()->assertJsonValidationErrors('client_id');
            $url = "/api/{$resource}/{$document['id']}";
            $this->patchJson($url, ['client_id' => $this->client->id, 'notes' => 'Preserved'])
                ->assertOk()->assertJsonPath('client_id', $this->client->id);
            $this->patchJson($url, ['client_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        }
        $this->postJson('/api/quotes/'.$documents['quotes']['id'].'/convert')->assertCreated()
            ->assertJsonPath('client.name', 'Historical client');
    }

    public function test_financial_delete_endpoints_preserve_data_for_every_status(): void
    {
        foreach (['invoices' => ['draft', 'sent', 'paid', 'partial', 'overdue', 'cancelled'],
            'quotes' => ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted']] as $resource => $statuses) {
            foreach ($statuses as $status) {
                $document = $this->document($resource, $status);
                $before = DB::table($resource)->where('id', $document['id'])->first();
                $this->deleteJson("/api/{$resource}/{$document['id']}")->assertStatus(409);
                $this->assertEquals($before, DB::table($resource)->where('id', $document['id'])->first());
            }
        }
        $invoice = $this->document('invoices');
        $payment = $this->payment($invoice);
        $this->deleteJson("/api/invoices/{$invoice['id']}/payments/{$payment['id']}")->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'amount' => 50]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'partial']);
        $this->assertDatabaseCount('invoice_items', 7);
        $this->assertDatabaseCount('quote_items', 6);
    }

    public function test_draft_archive_is_reversible_cached_and_read_only(): void
    {
        foreach (['invoices', 'quotes'] as $resource) {
            $document = $this->document($resource);
            $url = "/api/{$resource}/{$document['id']}";
            $this->getJson('/api/'.$resource)->assertOk();
            $this->getJson('/api/dashboard/summary')->assertOk();
            $archived = $this->patchJson($url.'/archive')->assertOk()->json('archived_at');
            $this->assertNotNull($archived);
            $this->patchJson($url.'/archive')->assertOk()->assertJsonPath('archived_at', $archived);
            $this->getJson('/api/'.$resource)->assertJsonPath('0.archived_at', $archived);
            $this->getJson($url)->assertOk()->assertJsonCount(1, 'items');
            $this->getJson('/api/dashboard/summary')->assertJsonPath($resource.'_count', 0);
            $this->patchJson($url, ['notes' => 'Forbidden'])->assertStatus(409);
            $this->patchJson($url.'/status', ['status' => 'sent'])->assertStatus(409);
            $this->deleteJson($url)->assertStatus(409);
            if ($resource === 'quotes') {
                $this->postJson($url.'/convert')->assertStatus(409);
                $item = $document['items'][0];
                $this->postJson($url.'/items', [])->assertStatus(409);
                $this->patchJson($url.'/items/'.$item['id'], ['quantity' => 2])->assertStatus(409);
                $this->deleteJson($url.'/items/'.$item['id'])->assertStatus(409);
            } else {
                $this->postJson($url.'/payments', [])->assertStatus(409);
            }
            $this->patchJson($url.'/restore')->assertOk()->assertJsonPath('archived_at', null);
            $this->patchJson($url.'/restore')->assertOk();
            $this->getJson('/api/'.$resource)->assertJsonPath('0.archived_at', null);
            $this->getJson('/api/dashboard/summary')->assertJsonPath($resource.'_count', 1);
            $this->patchJson($url, ['notes' => 'Allowed'])->assertOk();
        }
    }

    public function test_issued_documents_and_invoices_with_payments_cannot_be_archived(): void
    {
        foreach (['invoices' => ['sent', 'paid', 'partial', 'overdue', 'cancelled'],
            'quotes' => ['sent', 'accepted', 'rejected', 'expired', 'converted']] as $resource => $statuses) {
            foreach ($statuses as $status) {
                $document = $this->document($resource, $status);
                $this->patchJson("/api/{$resource}/{$document['id']}/archive")->assertStatus(409);
            }
        }
        $invoice = $this->document('invoices');
        $this->payment($invoice);
        $this->patchJson('/api/invoices/'.$invoice['id'].'/status', ['status' => 'draft'])->assertOk();
        $this->patchJson('/api/invoices/'.$invoice['id'].'/archive')->assertStatus(409);
    }

    public function test_archive_restore_and_deletion_remain_isolated_between_companies(): void
    {
        $invoice = $this->document('invoices');
        $quote = $this->document('quotes');
        $payment = $this->payment($invoice);
        $this->client->delete();
        $company = Company::create(['name' => 'Foreign company']);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));
        $this->getJson('/api/clients?archived=1')->assertJsonPath('total', 0);
        $this->deleteJson('/api/clients/'.$this->client->id)->assertNotFound();
        $this->patchJson('/api/clients/'.$this->client->id.'/restore')->assertNotFound();
        foreach (['invoices' => $invoice, 'quotes' => $quote] as $resource => $document) {
            foreach (['archive', 'restore'] as $action) {
                $this->patchJson("/api/{$resource}/{$document['id']}/{$action}")->assertNotFound();
            }
            $this->deleteJson("/api/{$resource}/{$document['id']}")->assertNotFound();
        }
        $this->deleteJson("/api/invoices/{$invoice['id']}/payments/{$payment['id']}")->assertNotFound();
        $this->assertSoftDeleted('clients', ['id' => $this->client->id]);
    }

    public function test_models_reject_physical_financial_deletion_including_empty_documents(): void
    {
        $invoice = $this->document('invoices');
        $quote = $this->document('quotes');
        $payment = $this->payment($invoice);
        $empty = Invoice::create(['company_id' => $this->owner->company_id, 'client_id' => $this->client->id,
            'invoice_number' => 'EMPTY-HISTORY', 'invoice_date' => '2026-11-06']);
        foreach ([$empty, Invoice::findOrFail($invoice['id']), Quote::findOrFail($quote['id']), Payment::findOrFail($payment['id'])] as $model) {
            try {
                $model->delete();
                $this->fail('Physical deletion should fail.');
            } catch (LogicException $exception) {
                $this->assertDatabaseHas($model->getTable(), ['id' => $model->id]);
            }
        }
        try {
            $this->client->forceDelete();
            $this->fail('Force deletion should fail.');
        } catch (LogicException $exception) {
            $this->assertDatabaseHas('clients', ['id' => $this->client->id]);
        }
    }

    public function test_database_restricts_parent_deletions_without_cascading_history(): void
    {
        $invoice = $this->document('invoices');
        $quote = $this->document('quotes');
        $this->payment($invoice);
        foreach ([['companies', $this->owner->company_id], ['clients', $this->client->id],
            ['invoices', $invoice['id']], ['quotes', $quote['id']]] as [$table, $id]) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $id)->delete());
                $this->fail('Foreign key should prevent deletion of '.$table);
            } catch (QueryException $exception) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseCount('quote_items', 1);
        foreach (['clients' => ['company_id'], 'invoices' => ['company_id', 'client_id'],
            'quotes' => ['company_id', 'client_id'], 'payments' => ['invoice_id'],
            'invoice_items' => ['invoice_id'], 'quote_items' => ['quote_id']] as $table => $columns) {
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array($key['columns'][0], $columns, true)) {
                    $this->assertSame('restrict', strtolower($key['on_delete']));
                }
            }
        }
    }
}
