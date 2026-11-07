<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentIntegrityMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_unused_rollback_preserve_existing_documents(): void
    {
        $migration = require database_path('migrations/2026_11_08_000001_add_document_integrity.php');
        $migration->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Historical']);
        $client = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Historical']);
        $invoice = DB::table('invoices')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'invoice_number' => 'KEEP-2020-1000', 'invoice_date' => '2020-11-06', 'total_amount' => '123.45']);
        $quote = DB::table('quotes')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'quote_number' => 'KEEP-2020-999', 'quote_date' => '2020-11-06', 'status' => 'converted']);
        $before = (array) DB::table('invoices')->where('id', $invoice)->first();
        $migration->up();
        $this->assertEquals($before, array_intersect_key((array) DB::table('invoices')->where('id', $invoice)->first(), $before));
        $this->assertNull(DB::table('invoices')->where('id', $invoice)->value('source_quote_id'));
        $this->assertDatabaseHas('quotes', ['id' => $quote, 'status' => 'converted']);
        $this->assertDatabaseCount('document_sequences', 0);
        $this->assertTrue(Schema::hasIndex('invoices', ['company_id', 'invoice_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('quotes', ['company_id', 'quote_number'], 'unique'));
        $migration->down();
        $migration->up();
    }

    public function test_used_counters_prevent_destructive_rollback(): void
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Counter']);
        DB::table('document_sequences')->insert(['company_id' => $company, 'document_type' => 'invoice', 'year' => 2026, 'last_value' => 1]);
        $migration = require database_path('migrations/2026_11_08_000001_add_document_integrity.php');
        try {
            $migration->down();
            $this->fail('Used metadata must be retained');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(Schema::hasTable('document_sequences'));
            $this->assertDatabaseCount('document_sequences', 1);
        } finally {
            DB::table('document_sequences')->delete(); // Isolated fixture only, so DatabaseMigrations can tear down.
        }
    }
}
