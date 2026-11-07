<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteConversionService;
use App\Support\CompanyCache;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class CommercialDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Commercial tests', 'invoice_prefix' => 'AC_%-']);
        $this->actor = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $this->client = Client::create(['company_id' => $company->id, 'name' => 'Preserved client']);
        $this->actingAs($this->actor);
    }

    private function payload(string $type = 'quote'): array
    {
        return ['client_id' => $this->client->id, $type.'_date' => today()->toDateString(),
            'status' => 'draft', 'notes' => 'Original notes',
            'items' => [['description' => 'Exact line', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20']]];
    }

    private function quote(string $state = 'draft'): array
    {
        $quote = $this->postJson('/api/quotes', $this->payload())->assertCreated()->json();
        if ($state !== 'draft') {
            $this->patchJson('/api/quotes/'.$quote['id'].'/status', ['status' => 'sent'])->assertOk();
        }
        if ($state === 'accepted') {
            $this->patchJson('/api/quotes/'.$quote['id'].'/status', ['status' => 'accepted'])->assertOk();
        }

        return $quote;
    }

    public function test_numeric_bootstrap_includes_archives_and_continues_past_999(): void
    {
        $year = today()->year;
        foreach (['invoice', 'quote'] as $type) {
            $model = $type === 'invoice' ? Invoice::class : Quote::class;
            foreach ([999, 1000] as $value) {
                $document = $model::create(['company_id' => $this->actor->company_id, 'client_id' => $this->client->id,
                    $type.'_number' => 'OLD-'.$year.'-'.$value, $type.'_date' => today(), 'status' => 'draft']);
                $document->archived_at = now();
                $document->save();
            }
            $result = $this->postJson('/api/'.$type.'s', $this->payload($type))->assertCreated()->json();
            $this->assertSame(($type === 'invoice' ? 'AC_%-' : 'DEV-').$year.'-1001', $result[$type.'_number']);
        }
        $this->assertDatabaseCount('document_sequences', 2);
    }

    public function test_prefix_changes_and_year_changes_never_rename_previous_documents(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:00', 'UTC'));
        $first = $this->postJson('/api/invoices', $this->payload('invoice'))->assertCreated()->json();
        $this->postJson('/api/quotes', $this->payload())->assertCreated()->assertJsonPath('quote_number', 'DEV-2026-001');
        $this->actor->company->update(['invoice_prefix' => 'NEW-']);
        $second = $this->postJson('/api/invoices', $this->payload('invoice'))->assertCreated()->json();
        $this->assertSame('NEW-2026-002', $second['invoice_number']);
        $this->travelTo(Carbon::parse('2027-01-01 00:01:00', 'UTC'));
        $this->postJson('/api/invoices', $this->payload('invoice'))->assertCreated()->assertJsonPath('invoice_number', 'NEW-2027-001');
        $this->postJson('/api/quotes', $this->payload())->assertCreated()->assertJsonPath('quote_number', 'DEV-2027-001');
        $this->assertDatabaseHas('invoices', ['id' => $first['id'], 'invoice_number' => 'AC_%-2026-001']);
        $this->travelBack();
    }

    public static function types(): array
    {
        return [['invoice'], ['quote']];
    }

    #[DataProvider('types')]
    public function test_same_numbers_are_allowed_in_different_tenants_only(string $type): void
    {
        $first = $this->postJson('/api/'.$type.'s', $this->payload($type))->assertCreated()->json();
        $company = Company::create(['name' => 'Other', 'invoice_prefix' => 'AC_%-']);
        $other = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $client = Client::create(['company_id' => $company->id, 'name' => 'Other']);
        $this->actingAs($other)->postJson('/api/'.$type.'s', array_replace($this->payload($type), ['client_id' => $client->id]))
            ->assertCreated()->assertJsonPath($type.'_number', $first[$type.'_number']);
        $this->expectException(QueryException::class);
        DB::table($type.'s')->insert(['company_id' => $company->id, 'client_id' => $client->id,
            $type.'_number' => $first[$type.'_number'], $type.'_date' => today()->toDateString()]);
    }

    public function test_conversion_is_idempotent_preserves_content_and_uses_company_prefix(): void
    {
        $draft = $this->quote();
        $quote = Quote::findOrFail($draft['id']);
        $quote->update(['discount_amount' => '10.00', 'total_amount' => '110.00']);
        $this->patchJson('/api/quotes/'.$quote->id.'/status', ['status' => 'sent'])->assertOk();
        $this->patchJson('/api/quotes/'.$quote->id.'/status', ['status' => 'accepted'])->assertOk();
        $this->client->delete();
        $beforeItems = DB::table('quote_items')->where('quote_id', $quote->id)->get()->toArray();
        $url = '/api/quotes/'.$quote->id.'/convert';
        $first = $this->postJson($url)->assertCreated()->assertJsonPath('source_quote_id', $quote->id)->assertJsonPath('discount_amount', '10.00')->assertJsonPath('total_amount', '110.00')->json();
        $this->postJson($url)->assertOk()->assertJsonPath('id', $first['id']);
        $this->assertStringStartsWith('AC_%-', $first['invoice_number']);
        $this->assertSame('draft', $first['document_status']);
        $this->assertSame('Original notes', $first['notes']);
        $this->assertSame('Exact line', $first['items'][0]['description']);
        $this->assertEquals($beforeItems, DB::table('quote_items')->where('quote_id', $quote->id)->get()->toArray());
        $this->getJson('/api/quotes/'.$quote->id)->assertJsonPath('invoice.id', $first['id']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('document_sequences', ['document_type' => 'invoice', 'last_value' => 1]);
    }

    public function test_conversion_sql_error_rolls_back_invoice_lines_counter_and_quote(): void
    {
        $quote = $this->quote('accepted');
        $key = CompanyCache::key($this->actor->company_id, CompanyCache::QUOTES);
        Cache::put($key, ['marker' => true], 60);
        Event::listen('eloquent.creating: '.InvoiceItem::class, fn () => DB::statement('INSERT INTO missing_conversion_test_table (id) VALUES (1)'));
        try {
            app(QuoteConversionService::class)->convert($this->actor, $quote['id']);
            $this->fail('SQL error expected');
        } catch (QueryException $exception) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertDatabaseCount('invoice_items', 0);
            $this->assertDatabaseMissing('document_sequences', ['document_type' => 'invoice']);
            $this->assertDatabaseHas('quotes', ['id' => $quote['id'], 'status' => 'accepted']);
            $this->assertSame(['marker' => true], Cache::get($key));
        } finally {
            Event::forget('eloquent.creating: '.InvoiceItem::class);
        }
    }

    public function test_line_overflow_rolls_back_insert_and_previous_totals(): void
    {
        $quote = $this->quote();
        $this->postJson('/api/quotes/'.$quote['id'].'/items', ['description' => 'Overflow', 'quantity' => '1', 'unit_price' => '9999999999.99'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('quote_items', 1);
        $this->assertDatabaseHas('quotes', ['id' => $quote['id'], 'total_amount' => 120]);
        $this->patchJson('/api/quotes/'.$quote['id'].'/items/'.$quote['items'][0]['id'], ['quantity' => '9999999999.99'])->assertUnprocessable();
        $this->assertDatabaseHas('quote_items', ['id' => $quote['items'][0]['id'], 'quantity' => 1, 'unit_price' => 100]);
    }

    public function test_transitions_freeze_content_and_preserve_terminal_documents(): void
    {
        $quote = $this->quote();
        $url = '/api/quotes/'.$quote['id'];
        $this->patchJson($url.'/status', ['status' => 'accepted'])->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'converted'])->assertUnprocessable();
        $this->postJson('/api/quotes', array_replace($this->payload(), ['status' => 'converted']))->assertUnprocessable();
        $this->patchJson($url.'/status', ['status' => 'sent'])->assertOk();
        $this->patchJson($url, ['notes' => 'Forbidden'])->assertStatus(409);
        $this->postJson($url.'/items', ['description' => 'Forbidden', 'quantity' => 1, 'unit_price' => 1])->assertStatus(409);
        $this->deleteJson($url.'/items/'.$quote['items'][0]['id'])->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'rejected'])->assertOk();
        $this->patchJson($url.'/status', ['status' => 'draft'])->assertStatus(409);
        $this->postJson($url.'/convert')->assertStatus(409);
        $this->deleteJson($url)->assertStatus(409);
        $this->assertDatabaseCount('quote_items', 1);
    }

    public function test_expiration_and_effective_dates_are_checked(): void
    {
        $quote = $this->quote();
        $url = '/api/quotes/'.$quote['id'];
        $this->patchJson($url, ['valid_until' => today()->subDay()->toDateString()])->assertUnprocessable();
        $this->patchJson($url, ['quote_date' => today()->subDays(3)->toDateString(), 'valid_until' => today()->subDay()->toDateString()])->assertOk();
        $this->patchJson($url.'/status', ['status' => 'sent'])->assertOk();
        $this->patchJson($url.'/status', ['status' => 'accepted'])->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'expired'])->assertOk();
    }

    public function test_legacy_converted_without_link_is_reported_without_repair(): void
    {
        $quote = $this->quote();
        DB::table('quotes')->where('id', $quote['id'])->update(['status' => 'converted']);
        $before = DB::table('quotes')->where('id', $quote['id'])->first();
        $this->artisan('documents:audit')->assertFailed();
        $this->postJson('/api/quotes/'.$quote['id'].'/convert')->assertStatus(409);
        $this->assertEquals($before, DB::table('quotes')->where('id', $quote['id'])->first());
        $this->assertDatabaseCount('invoices', 0);
    }

    public static function roles(): array
    {
        return [['admin', 201], ['employee', 403], ['comptable', 201]];
    }

    #[DataProvider('roles')]
    public function test_conversion_permissions_and_tenant_isolation(string $role, int $status): void
    {
        $quote = $this->quote('accepted');
        $this->actor->role = $role;
        $this->actor->save();
        $this->postJson('/api/quotes/'.$quote['id'].'/convert')->assertStatus($status);
        $other = Company::create(['name' => 'Other']);
        $user = User::factory()->create(['company_id' => $other->id, 'role' => $role]);
        $this->actingAs($user)->postJson('/api/quotes/'.$quote['id'].'/convert')->assertNotFound();
        $this->assertDatabaseCount('invoices', $status === 201 ? 1 : 0);
    }

    public function test_database_rejects_duplicate_conversion_and_cross_tenant_link(): void
    {
        $one = $this->quote('accepted');
        $this->postJson('/api/quotes/'.$one['id'].'/convert')->assertCreated();
        $two = $this->quote('accepted');
        $other = Company::create(['name' => 'Other']);
        $client = Client::create(['company_id' => $other->id, 'name' => 'Other']);
        foreach ([[$this->actor->company_id, $this->client->id, $one['id']], [$other->id, $client->id, $two['id']]] as [$company, $clientId, $source]) {
            try {
                DB::table('invoices')->insert(['company_id' => $company, 'client_id' => $clientId, 'source_quote_id' => $source,
                    'invoice_number' => 'INVALID-LINK-'.$company, 'invoice_date' => today()->toDateString()]);
                $this->fail('Database constraint expected');
            } catch (QueryException $exception) {
                $this->assertDatabaseCount('invoices', 1);
            }
        }
    }

    public function test_failed_document_creation_does_not_consume_a_number(): void
    {
        foreach (['invoice', 'quote'] as $type) {
            $data = $this->payload($type);
            $data['items'][0] = ['description' => 'Overflow', 'quantity' => '2', 'unit_price' => '9999999999.99'];
            $this->postJson('/api/'.$type.'s', $data)->assertUnprocessable();
            $this->assertDatabaseCount($type.'s', 0);
            $this->assertDatabaseMissing('document_sequences', ['document_type' => $type]);
        }
    }

    public function test_direct_model_writes_cannot_change_frozen_quote_content(): void
    {
        $data = $this->quote('sent');
        $quote = Quote::findOrFail($data['id']);
        $item = $quote->items()->firstOrFail();
        foreach ([fn () => $quote->update(['notes' => 'Forbidden']), fn () => $item->update(['quantity' => 2]),
            fn () => $item->delete(), fn () => $quote->items()->create(['description' => 'Forbidden', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1])] as $write) {
            try {
                $write();
                $this->fail('Frozen write must be rejected');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('quote_items', 1);
        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'notes' => 'Original notes']);
        $this->assertDatabaseHas('quote_items', ['id' => $item->id, 'quantity' => 1]);
    }

    public function test_document_numbers_cannot_be_changed_even_on_drafts(): void
    {
        foreach (['invoice', 'quote'] as $type) {
            $document = $this->postJson('/api/'.$type.'s', $this->payload($type))->assertCreated()->json();
            $model = $type === 'invoice' ? Invoice::class : Quote::class;
            try {
                $model::findOrFail($document['id'])->update([$type.'_number' => 'RENUMBERED']);
                $this->fail('Renumbering must be refused');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertDatabaseHas($type.'s', ['id' => $document['id'], $type.'_number' => $document[$type.'_number']]);
        }
    }

    public function test_conversion_link_and_item_parent_are_permanent(): void
    {
        $quote = $this->quote('accepted');
        $invoice = $this->postJson('/api/quotes/'.$quote['id'].'/convert')->assertCreated()->json();
        try {
            Invoice::findOrFail($invoice['id'])->forceFill(['source_quote_id' => null])->save();
            $this->fail('Conversion link must remain');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        try {
            Quote::findOrFail($quote['id'])->items()->firstOrFail()->update(['quote_id' => $quote['id'] + 999]);
            $this->fail('Line parent must remain');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'source_quote_id' => $quote['id']]);
        $this->assertDatabaseHas('quote_items', ['id' => $quote['items'][0]['id'], 'quote_id' => $quote['id']]);
    }
}
