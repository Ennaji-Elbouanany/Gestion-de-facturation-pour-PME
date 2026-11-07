<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\MoneyCalculator;
use App\Support\CompanyCache;
use App\Support\ExactSql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReadOptimizationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return [['admin'], ['employee'], ['comptable']];
    }

    #[DataProvider('roles')]
    public function test_paged_reads_are_authorized_and_tenant_scoped(string $role): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $user = User::factory()->create(['company_id' => $a->id, 'role' => $role]);
        $this->actingAs($user);
        $one = Client::create(['company_id' => $a->id, 'name' => 'Own']);
        $foreign = Client::create(['company_id' => $b->id, 'name' => 'Foreign']);
        Product::create(['company_id' => $a->id, 'name' => 'Own', 'unit_price' => '0']);
        Product::create(['company_id' => $b->id, 'name' => 'Foreign', 'unit_price' => '0']);
        foreach (['invoices' => 'invoice_number', 'quotes' => 'quote_number'] as $table => $number) {
            foreach ([[$a, $one], [$b, $foreign]] as [$company,$client]) {
                DB::table($table)->insert(['company_id' => $company->id, 'client_id' => $client->id, $number => 'R-'.$company->id, ($table === 'invoices' ? 'invoice_date' : 'quote_date') => '2026-01-01']);
            }
        }
        foreach (['clients', 'products', 'invoices', 'quotes'] as $resource) {
            $this->getJson('/api/'.$resource.'?per_page=25')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.company_id', $a->id);
        }
        $this->getJson('/api/payments?per_page=25')->assertStatus($role === 'employee' ? 403 : 200);
        $this->getJson('/api/invoices?per_page=25&client_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/quotes?per_page=25&client_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/dashboard/financial?company_id='.$b->id)->assertStatus($role === 'employee' ? 403 : 422);
    }

    public function test_exact_sql_sum_and_order_never_use_binary_financial_arithmetic(): void
    {
        ExactSql::boot();
        $query = DB::query()->fromSub(DB::query()->selectRaw("'90071992547409.90' AS amount")->unionAll(DB::query()->selectRaw("'0.01' AS amount")), 'numbers');
        $result = $query->selectRaw(ExactSql::sum('amount').' AS amount')->first();
        $this->assertSame('90071992547409.91', MoneyCalculator::money($result->amount));
    }

    public function test_publisher_cache_write_failure_does_not_repeat_callback(): void
    {
        $calls = 0;
        Cache::shouldReceive('get')->with('probe')->once()->andReturn(null);
        Cache::shouldReceive('get')->with('probe:generation', 'initial')->twice()->andReturn('initial');
        Cache::shouldReceive('lock')->with('probe:publish', 5)->once()->andReturn(new class
        {
            public function block($seconds, $callback)
            {
                return $callback();
            }
        });
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('Simulated cache SET failure'));
        $result = CompanyCache::remember('probe', now()->addMinute(), function () use (&$calls) {
            $calls++;

            return 'read-value';
        });
        $this->assertSame('read-value', $result);
        $this->assertSame(1, $calls);
    }

    public function test_callback_error_is_not_masked_by_cache_fallback(): void
    {
        $this->expectExceptionMessage('SQL failure must propagate');
        CompanyCache::remember('sql-probe', now()->addMinute(), fn () => throw new \RuntimeException('SQL failure must propagate'));
    }

    public function test_date_only_upper_bound_and_null_timestamp_cursor(): void
    {
        $company = Company::create(['name' => 'A']);
        $this->actingAs(User::factory()->create(['company_id' => $company->id]));
        for ($i = 0; $i < 4; $i++) {
            DB::table('clients')->insert(['company_id' => $company->id, 'name' => 'Old '.$i, 'created_at' => null]);
        }
        $one = $this->getJson('/api/clients?per_page=2&pagination=cursor')->assertOk();
        $this->getJson($one->json('next_page_url'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/clients?per_page=25&to=2026-12-31')->assertOk();
        $this->getJson('/api/clients?per_page=25&cursor=bad&pagination=cursor')->assertUnprocessable();
    }

    public function test_new_reads_require_authentication(): void
    {
        foreach (['dashboard/financial', 'clients?per_page=25', 'products?per_page=25', 'invoices?per_page=25', 'quotes?per_page=25', 'payments?per_page=25'] as $path) {
            $this->getJson('/api/'.$path)->assertUnauthorized();
        }
    }

    public function test_list_index_audit_is_read_only(): void
    {
        $company = Company::create(['name' => 'A']);
        $this->artisan('performance:audit', ['--company' => $company->id, '--explain' => true])->expectsOutputToContain('Lecture seule')->assertExitCode(0);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('clients', 0);
    }
}
