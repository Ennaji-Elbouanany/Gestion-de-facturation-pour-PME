<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class MySqlPaymentConcurrencyTest extends TestCase
{
    use ConcurrentProcesses;
    use DatabaseMigrations;

    private User $actor;

    private Invoice $invoice;

    public function runDatabaseMigrations()
    {
        $config = config('database.connections.mysql');
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 8 required; SQLite cannot prove row locking.');
        }
        if (! app()->environment('testing') || ! str_ends_with($config['database'], '_test')
            || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new \RuntimeException('Destructive concurrency tests require a local *_test database.');
        }
        if (! str_starts_with((string) DB::selectOne('SELECT VERSION() AS version')->version, '8.')) {
            $this->markTestSkipped('Real MySQL 8 required.');
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // Test database only: production rollback deliberately refuses loss of snapshots/revisions.
            $this->artisan('db:wipe', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Concurrency test']);
        $this->actor = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $client = Client::create(['company_id' => $company->id, 'name' => 'Client']);
        $this->invoice = Invoice::create(['company_id' => $company->id, 'client_id' => $client->id,
            'invoice_number' => 'CONCURRENCY-TEST', 'invoice_date' => today(), 'total_amount' => 100, 'status' => 'sent']);
    }

    private function race(array $requests): array
    {
        $operations = array_map(fn ($request) => ['payment', $this->invoice->id, $request[0], $request[1]], $requests);
        $results = $this->runConcurrent($operations, fn () => $this->invoice->newQuery()->whereKey($this->invoice->id)->lockForUpdate()->firstOrFail());
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    public function test_two_simultaneous_payments_cannot_overpay(): void
    {
        $this->assertSame([201, 422], $this->race([[0, '70.00'], [0, '70.00']]));
        $this->assertSame('70.00', InvoiceService::paid($this->invoice, false));
        $this->assertSame(1, $this->invoice->payments()->count());
    }

    public function test_two_distinct_payment_corrections_cannot_overpay(): void
    {
        $service = app(PaymentService::class);
        $data = ['amount' => '20.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash'];
        $first = $service->create($this->actor, $this->invoice->id, $data);
        $second = $service->create($this->actor, $this->invoice->id, $data);
        $this->assertSame([201, 422], $this->race([[$first->id, '60.00'], [$second->id, '60.00']]));
        $this->assertSame('80.00', InvoiceService::paid($this->invoice, false));
        $this->assertDatabaseCount('payment_revisions', 1);
    }

    public function test_payment_and_cancellation_cannot_both_succeed(): void
    {
        $this->assertSame([201, 409], $this->race([[0, '30.00'], [-1, '0']]));
        $invoice = $this->invoice->fresh();
        $paid = InvoiceService::paid($invoice, false);
        $this->assertTrue(($invoice->document_status === 'cancelled' && $paid === '0.00')
            || ($invoice->document_status === 'issued' && $paid === '30.00'));
    }

    public function test_two_edits_of_the_same_payment_detect_a_stale_version(): void
    {
        $payment = app(PaymentService::class)->create($this->actor, $this->invoice->id,
            ['amount' => '20.00', 'payment_date' => today()->toDateString(), 'payment_method' => 'cash']);
        $this->assertSame([201, 409], $this->race([[$payment->id, '30.00'], [$payment->id, '40.00']]));
        $this->assertSame(2, $payment->fresh()->version);
        $this->assertDatabaseCount('payment_revisions', 1);
    }
}
