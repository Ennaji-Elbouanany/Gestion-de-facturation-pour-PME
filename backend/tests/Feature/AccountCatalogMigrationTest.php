<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountCatalogMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private function emailMigration(): object
    {
        return require database_path('migrations/2026_11_09_000001_add_client_email_tenant_uniqueness.php');
    }

    public function test_client_upgrade_preserves_rows_and_refuses_duplicates_before_ddl(): void
    {
        $migration = $this->emailMigration();
        $migration->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Keep']);
        $one = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Keep', 'email' => 'same@example.test', 'deleted_at' => now()]);
        $two = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Duplicate', 'email' => 'same@example.test']);
        $before = DB::table('clients')->orderBy('id')->get()->toJson();
        try {
            $migration->up();
            $this->fail('Duplicate must block DDL');
        } catch (\RuntimeException $exception) {
            $this->assertFalse(Schema::hasIndex('clients', ['company_id', 'email'], 'unique'));
            $this->assertSame($before, DB::table('clients')->orderBy('id')->get()->toJson());
        }
        DB::table('clients')->where('id', $two)->update(['email' => null]); // Isolated fixture correction only.
        $before = DB::table('clients')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('clients', ['company_id', 'email'], 'unique'));
        $this->assertSame($before, DB::table('clients')->orderBy('id')->get()->toJson());
        $this->assertNotNull(DB::table('clients')->where('id', $one)->value('deleted_at'));
        $migration->down();
        $migration->up();
        DB::table('clients')->where('id', $one)->update(['deleted_at' => null]); // Fixture cleanup for retention rollback guard.
    }

    public function test_noncanonical_historical_email_is_reported_not_rewritten(): void
    {
        $migration = $this->emailMigration();
        $migration->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Keep']);
        $id = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Keep', 'email' => ' OLD@example.test ']);
        try {
            $migration->up();
            $this->fail('Noncanonical email must block DDL');
        } catch (\RuntimeException $exception) {
            $this->assertDatabaseHas('clients', ['id' => $id, 'email' => ' OLD@example.test ']);
        }
        $this->artisan('accounts-catalog:audit')->expectsOutputToContain('non normalisé')->assertExitCode(1);
        $this->assertDatabaseHas('clients', ['id' => $id, 'email' => ' OLD@example.test ']);
        DB::table('clients')->where('id', $id)->update(['email' => 'old@example.test']);
        $migration->up();
    }

    public function test_product_constraint_roundtrip_preserves_historical_rows(): void
    {
        $migration = require database_path('migrations/2026_11_09_000002_restrict_historical_product_deletions.php');
        $company = DB::table('companies')->insertGetId(['name' => 'Keep']);
        $client = DB::table('clients')->insertGetId(['company_id' => $company, 'name' => 'Keep']);
        $product = DB::table('products')->insertGetId(['company_id' => $company, 'name' => 'Keep', 'unit_price' => '12.34']);
        $quote = DB::table('quotes')->insertGetId(['company_id' => $company, 'client_id' => $client, 'quote_number' => 'OLD', 'quote_date' => '2020-01-01', 'status' => 'converted']);
        DB::table('quote_items')->insert(['quote_id' => $quote, 'product_id' => $product, 'description' => 'Keep', 'quantity' => 1, 'unit_price' => '12.34', 'total' => '12.34']);
        $before = DB::table('quote_items')->get()->toJson();
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('quote_items')->get()->toJson());
        foreach (['quote_items', 'invoice_items'] as $table) {
            $keys = array_values(array_filter(Schema::getForeignKeys($table), fn ($key) => $key['columns'] === ['product_id']));
            $this->assertSame('restrict', strtolower($keys[0]['on_delete']));
        }
    }
}
