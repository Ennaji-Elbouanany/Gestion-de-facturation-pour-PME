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
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MySqlPaymentConcurrencyTest extends TestCase
{
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
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'billing-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $processes = [];
        $config = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => 'null', 'MYSQL_ATTR_SSL_CA' => 'null',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
        DB::beginTransaction();
        try {
            $this->invoice->newQuery()->whereKey($this->invoice->id)->lockForUpdate()->firstOrFail();
            foreach ($requests as $index => [$paymentId, $amount]) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/payment_worker.php'), (string) $this->actor->id,
                    (string) $this->invoice->id, (string) $paymentId, $amount, $directory.'/'.$index.'.ready', $directory.'/go'], base_path(), $env);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 12;
            while (count(glob($directory.'/*.ready')) !== count($requests)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Worker exited before synchronization: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Workers failed to reach the synchronization barrier.');
                }
                usleep(10000);
            }
            file_put_contents($directory.'/go', 'go');
            $deadline = microtime(true) + 5;
            while (count(glob($directory.'/*.attempt')) !== count($requests)) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not attempt the financial operation.');
                }
                usleep(10000);
            }
            // Both workers must remain blocked behind the actual parent-row lock.
            usleep(300000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Worker bypassed the invoice lock.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
            }
            sort($results);

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (str_starts_with(realpath($directory), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'billing-')) {
                foreach (glob($directory.'/*') as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
        }
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
