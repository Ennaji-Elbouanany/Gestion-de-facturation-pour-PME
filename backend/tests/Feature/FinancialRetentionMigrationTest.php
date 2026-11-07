<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FinancialRetentionMigrationTest extends TestCase
{
    // No enclosing transaction: SQLite has to rebuild existing parent tables.
    use DatabaseMigrations;

    private function constraintsMigration(): object
    {
        return require database_path('migrations/2026_11_06_000002_restrict_financial_parent_deletions.php');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['companies', 'users', 'clients', 'products', 'invoices', 'quotes', 'invoice_items', 'quote_items', 'payments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $result;
    }

    public function test_upgrade_and_constraint_rollback_preserve_existing_financial_rows(): void
    {
        $migration = $this->constraintsMigration();
        $migration->down();
        $columns = require database_path('migrations/2026_11_06_000001_add_retention_archive_columns.php');
        $columns->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Before upgrade']);
        $client = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Client']);
        $product = DB::table('products')->insertGetId(['company_id' => $company, 'name' => 'Product', 'unit_price' => 10]);
        $invoice = DB::table('invoices')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'invoice_number' => 'FAC-HISTORY', 'invoice_date' => '2026-11-06', 'total_amount' => 10]);
        $quote = DB::table('quotes')->insertGetId(['company_id' => $company, 'client_id' => $client,
            'quote_number' => 'DEV-HISTORY', 'quote_date' => '2026-11-06', 'total_amount' => 10]);
        DB::table('invoice_items')->insert(['invoice_id' => $invoice, 'product_id' => $product,
            'description' => 'Saved line', 'unit_price' => 10, 'subtotal' => 10, 'total' => 10]);
        DB::table('quote_items')->insert(['quote_id' => $quote, 'product_id' => $product,
            'description' => 'Saved line', 'unit_price' => 10, 'subtotal' => 10, 'total' => 10]);
        DB::table('payments')->insert(['invoice_id' => $invoice, 'amount' => 5,
            'payment_date' => '2026-11-06', 'payment_method' => 'cash']);
        $before = $this->snapshot();
        $columns->up();
        $afterColumns = $this->snapshot();
        foreach ($afterColumns as $table => $rows) {
            foreach ($rows as $index => $row) {
                $original = clone $row;
                unset($original->deleted_at, $original->archived_at);
                $this->assertEquals($before[$table][$index], $original);
            }
        }
        $before = $afterColumns;
        $migration->up();
        $this->assertEquals($before, $this->snapshot());
        $migration->down();
        $this->assertEquals($before, $this->snapshot());
        $migration->up();
        $this->assertEquals($before, $this->snapshot());
        // Rebuilds must retain null-on-delete product references and indexes too.
        DB::table('products')->where('id', $product)->delete();
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice, 'product_id' => null, 'total' => 10]);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quote, 'product_id' => null, 'total' => 10]);
        $this->assertTrue(Schema::hasIndex('invoices', ['company_id', 'invoice_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('quotes', ['company_id', 'quote_number'], 'unique'));
        $this->assertTrue(Schema::hasIndex('invoices', ['archived_at']));
    }

    public function test_preflight_rejects_unexpected_constraints_before_modifying_other_tables(): void
    {
        $migration = $this->constraintsMigration();
        $migration->down();
        $key = collect(Schema::getForeignKeys('payments'))->firstWhere('columns', ['invoice_id']);
        Schema::table('payments', function (Blueprint $table) use ($key): void {
            DB::getDriverName() === 'sqlite' ? $table->dropForeign(['invoice_id']) : $table->dropForeign($key['name']);
        });
        try {
            $migration->up();
            $this->fail('Preflight should reject a missing relation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('payments.invoice_id', $exception->getMessage());
            $clientKey = collect(Schema::getForeignKeys('clients'))->firstWhere('columns', ['company_id']);
            $this->assertSame('cascade', strtolower($clientKey['on_delete']));
        } finally {
            Schema::table('payments', fn (Blueprint $table) => $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete());
            $migration->up();
        }
    }

    public function test_archive_columns_cannot_be_rolled_back_while_archives_exist(): void
    {
        $company = Company::create(['name' => 'Archived company']);
        $client = Client::create(['company_id' => $company->id, 'name' => 'Archived']);
        $client->delete();
        $migration = require database_path('migrations/2026_11_06_000001_add_retention_archive_columns.php');
        try {
            $migration->down();
            $this->fail('Removing archive markers should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertTrue(Schema::hasColumn('clients', 'deleted_at'));
            $this->assertSoftDeleted('clients', ['id' => $client->id]);
        } finally {
            $client->restore();
        }
    }
}
