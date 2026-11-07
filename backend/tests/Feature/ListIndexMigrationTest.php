<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ListIndexMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_new_indexes_preserve_rows_and_can_be_reapplied(): void
    {
        $migration = require database_path('migrations/2026_11_10_000001_add_measured_list_indexes.php');
        $migration->down();
        $company = DB::table('companies')->insertGetId(['name' => 'Keep']);
        DB::table('clients')->insert(['company_id' => $company, 'name' => 'Archived', 'deleted_at' => now()]);
        $before = DB::table('clients')->get()->toJson();
        $migration->up();
        $this->assertSame($before, DB::table('clients')->get()->toJson());
        $this->assertTrue(Schema::hasIndex('clients', ['company_id', 'deleted_at', 'created_at', 'id']));
        $migration->down();
        $migration->up();
        DB::table('clients')->update(['deleted_at' => null]); // Fixture cleanup for the lot 1 rollback guard.
    }

    public function test_unexpected_index_is_rejected_before_other_ddl(): void
    {
        $migration = require database_path('migrations/2026_11_10_000001_add_measured_list_indexes.php');
        $migration->down();
        Schema::table('invoices', fn ($table) => $table->index(['company_id', 'invoice_date', 'id'], 'other_period_scope'));
        try {
            $migration->up();
            $this->fail('Unexpected schema must block migration');
        } catch (\RuntimeException $e) {
            $this->assertFalse(Schema::hasIndex('clients', 'clients_list_scope'));
        }
        Schema::table('invoices', fn ($table) => $table->dropIndex('other_period_scope'));
        $migration->up();
    }
}
