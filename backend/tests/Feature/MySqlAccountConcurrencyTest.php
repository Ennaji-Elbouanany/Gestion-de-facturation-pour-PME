<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class MySqlAccountConcurrencyTest extends TestCase
{
    use ConcurrentProcesses;
    use DatabaseMigrations;

    private User $actor;

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
        $company = Company::create(['name' => 'Account concurrency']);
        $this->actor = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'password' => bcrypt('OriginalSecret!2026')]);
    }

    private function statuses(array $results): array
    {
        $values = array_column($results, 'status');
        sort($values);

        return $values;
    }

    public function test_login_racing_password_change_leaves_no_old_password_token(): void
    {
        $results = $this->runConcurrent([
            ['http', 'POST', '/api/login', ['email' => $this->actor->email, 'password' => 'OriginalSecret!2026']],
            ['http', 'PATCH', '/api/settings/security', ['current_password' => 'OriginalSecret!2026', 'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026']],
        ], fn () => User::whereKey($this->actor->id)->lockForUpdate()->firstOrFail());
        $this->assertContains($this->statuses($results), [[200, 200], [200, 401]]);
        $this->assertTrue(Hash::check('ReplacementSecret!2026', $this->actor->fresh()->password));
        $this->assertSame(0, $this->actor->tokens()->count());
    }

    public function test_simultaneous_resets_consume_the_link_once(): void
    {
        $reset = Password::createToken($this->actor);
        $payload = ['email' => $this->actor->email, 'token' => $reset, 'password' => 'ReplacementSecret!2026', 'password_confirmation' => 'ReplacementSecret!2026'];
        $results = $this->runConcurrent([['http', 'POST', '/api/reset-password', $payload], ['http', 'POST', '/api/reset-password', $payload]],
            fn () => User::whereKey($this->actor->id)->lockForUpdate()->firstOrFail());
        $this->assertSame([200, 422], $this->statuses($results));
        $this->assertSame(0, $this->actor->tokens()->count());
    }

    public function test_simultaneous_client_creations_cannot_duplicate_email(): void
    {
        $payload = ['name' => 'Buyer', 'email' => 'same@example.test'];
        $results = $this->runConcurrent([['http', 'POST', '/api/clients', $payload], ['http', 'POST', '/api/clients', $payload]],
            fn () => Company::whereKey($this->actor->company_id)->lockForUpdate()->firstOrFail());
        $this->assertSame([201, 422], $this->statuses($results));
        $this->assertSame(1, Client::where('company_id', $this->actor->company_id)->count());
    }
}
