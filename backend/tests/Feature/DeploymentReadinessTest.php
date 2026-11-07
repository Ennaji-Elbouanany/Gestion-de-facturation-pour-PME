<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_reports_release_without_authentication_or_writes(): void
    {
        config(['deployment.release_sha' => str_repeat('a', 40)]);
        $before = DB::table('migrations')->count();
        $this->getJson('/ready')->assertOk()->assertExactJson(['ready' => true, 'release_sha' => str_repeat('a', 40)])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, DB::table('migrations')->count());
    }

    public function test_pending_migration_blocks_readiness_without_applying_it(): void
    {
        $migration = DB::table('migrations')->orderByDesc('id')->value('migration');
        DB::table('migrations')->where('migration', $migration)->delete();
        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('ready', false);
        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
    }

    public function test_database_failure_is_generic_and_does_not_disclose_details(): void
    {
        DB::partialMock()->shouldReceive('select')->with('SELECT 1')->once()
            ->andThrow(new \RuntimeException('secret-host password=do-not-disclose'));
        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('ready', false)
            ->assertDontSee('secret-host')->assertDontSee('do-not-disclose');
    }
}
