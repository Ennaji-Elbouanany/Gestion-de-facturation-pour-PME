<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
