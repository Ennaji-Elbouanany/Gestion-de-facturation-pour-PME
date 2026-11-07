<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class MySqlDocumentConcurrencyTest extends TestCase
{
    use ConcurrentProcesses;
    use DatabaseMigrations;

    private User $actor;

    private Client $client;

    private Quote $quote;

    public function runDatabaseMigrations()
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real MySQL 8 row locks required.');
        }
        $connection = DB::connection();
        if (! app()->environment('testing') || ! str_ends_with($connection->getDatabaseName(), '_test')
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Only local test databases are allowed.');
        }
        if (! str_starts_with(DB::selectOne('SELECT VERSION() AS version')->version, '8.')) {
            $this->markTestSkipped('MySQL 8 required.');
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('db:wipe', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Concurrency', 'invoice_prefix' => 'SEQ-']);
        $this->actor = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $this->client = Client::create(['company_id' => $company->id, 'name' => 'Client']);
        $service = app(QuoteService::class);
        $this->quote = $service->create($this->actor, ['client_id' => $this->client->id, 'quote_date' => today()->toDateString(),
            'items' => [['description' => 'Source', 'quantity' => '1', 'unit_price' => '100.00']]]);
        $service->transition($this->actor, $this->quote->id, 'sent');
        $service->transition($this->actor, $this->quote->id, 'accepted');
    }

    private function race(array $requests): array
    {
        return $this->runConcurrent($requests, fn () => Company::whereKey($this->actor->company_id)->lockForUpdate()->firstOrFail());
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    public function test_two_invoice_creations_have_distinct_complete_numbers(): void
    {
        $results = $this->race([['invoice', $this->client->id], ['invoice', $this->client->id]]);
        $this->assertSame([201, 201], $this->statuses($results));
        $this->assertCount(2, array_unique(array_column($results, 'number')));
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('invoice_items', 2);
    }

    public function test_two_quote_creations_have_distinct_numbers(): void
    {
        $results = $this->race([['quote', $this->client->id], ['quote', $this->client->id]]);
        $this->assertSame([201, 201], $this->statuses($results));
        $this->assertCount(2, array_unique(array_column($results, 'number')));
        $this->assertDatabaseCount('quotes', 3);
    }

    public function test_invoice_creation_and_conversion_share_the_same_counter(): void
    {
        $results = $this->race([['invoice', $this->client->id], ['convert', $this->quote->id]]);
        $this->assertSame([201, 201], $this->statuses($results));
        $this->assertCount(2, array_unique(array_column($results, 'number')));
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('document_sequences', ['document_type' => 'invoice', 'last_value' => 2]);
    }

    public function test_two_conversions_return_the_same_invoice_without_consuming_two_numbers(): void
    {
        $results = $this->race([['convert', $this->quote->id], ['convert', $this->quote->id]]);
        $this->assertSame([200, 201], $this->statuses($results));
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('document_sequences', ['document_type' => 'invoice', 'last_value' => 1]);
    }

    public function test_conversion_and_edit_cannot_change_accepted_source(): void
    {
        $results = $this->race([['convert', $this->quote->id], ['edit', $this->quote->id]]);
        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('quotes', ['id' => $this->quote->id, 'status' => 'converted', 'notes' => null, 'total_amount' => 100]);
        $this->assertDatabaseHas('invoice_items', ['description' => 'Source', 'total' => 100]);
    }
}
