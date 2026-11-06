<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialIntegrityMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_keeps_existing_amounts_statuses_and_references_untouched(): void
    {
        $migration = require database_path('migrations/2026_11_07_000001_add_financial_integrity_fields.php');
        $migration->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Existing']);
        $client = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Existing']);
        $invoice = DB::table('invoices')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'invoice_number' => 'LEGACY', 'invoice_date' => '2026-11-06', 'status' => 'paid', 'total_amount' => '123.45']);
        $payment = DB::table('payments')->insertGetId(['invoice_id' => $invoice, 'amount' => '23.45',
            'payment_method' => 'cash', 'payment_date' => '2026-11-06']);
        $beforeInvoice = (array) DB::table('invoices')->where('id', $invoice)->first();
        $beforePayment = (array) DB::table('payments')->where('id', $payment)->first();
        $migration->up();
        $afterInvoice = (array) DB::table('invoices')->where('id', $invoice)->first();
        $afterPayment = (array) DB::table('payments')->where('id', $payment)->first();
        $this->assertEquals($beforeInvoice, array_intersect_key($afterInvoice, $beforeInvoice));
        $this->assertEquals($beforePayment, array_intersect_key($afterPayment, $beforePayment));
        $this->assertNull($afterInvoice['document_status']);
        $this->assertSame(1, (int) $afterPayment['version']);
        $this->assertTrue(Schema::hasIndex('payments', ['invoice_id', 'idempotency_key'], 'unique'));
        $this->assertDatabaseCount('payment_revisions', 0);
    }

    public static function retainedPaymentMetadata(): array
    {
        return [
            [['idempotency_key' => 'cf03c798-2506-42f0-b33f-a27e444e2f6a']],
            [['request_hash' => str_repeat('a', 64)]],
            [['version' => 2]],
        ];
    }

    #[DataProvider('retainedPaymentMetadata')]
    public function test_rollback_preserves_used_payment_metadata_without_snapshot_or_revision(array $metadata): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Legacy rollback test']);
        $client = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Legacy client']);
        $invoice = DB::table('invoices')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'invoice_number' => 'LEGACY-KEY', 'invoice_date' => '2026-11-06', 'status' => 'sent', 'total_amount' => '100.00']);
        $payment = DB::table('payments')->insertGetId(array_merge(['invoice_id' => $invoice, 'amount' => '10.00',
            'payment_method' => 'cash', 'payment_date' => '2026-11-06'], $metadata));
        $before = (array) DB::table('payments')->where('id', $payment)->first();
        $migration = require database_path('migrations/2026_11_07_000001_add_financial_integrity_fields.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse loss of used payment metadata');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('idempotence', $exception->getMessage());
            $this->assertEquals($before, (array) DB::table('payments')->where('id', $payment)->first());
            $this->assertTrue(Schema::hasTable('payment_revisions'));
            $this->assertTrue(Schema::hasColumn('invoices', 'issued_snapshot'));
        } finally {
            // Only this isolated fixture: let DatabaseMigrations tear its schema down normally.
            if (Schema::hasColumn('payments', 'idempotency_key')) {
                DB::table('payments')->where('id', $payment)->update(['idempotency_key' => null, 'request_hash' => null, 'version' => 1]);
            }
        }
    }
}
