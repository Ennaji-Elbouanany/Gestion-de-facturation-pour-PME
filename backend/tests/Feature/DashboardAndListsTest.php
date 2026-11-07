<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\CompanyCache;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardAndListsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['company_id' => Company::create(['name' => 'A'])->id, 'role' => 'admin']);
        $this->client = Client::create(['company_id' => $this->actor->company_id, 'name' => 'Same name']);
        $this->actingAs($this->actor);
    }

    private function invoice(array $attributes = []): int
    {
        return DB::table('invoices')->insertGetId([...['company_id' => $this->actor->company_id, 'client_id' => $this->client->id,
            'invoice_number' => 'INV-'.Str::uuid(), 'invoice_date' => today()->toDateString(), 'due_date' => today()->subDay()->toDateString(),
            'document_status' => 'issued', 'status' => 'sent', 'subtotal' => '100.00', 'tax_amount' => '20.00', 'total_amount' => '120.00',
            'issued_snapshot' => json_encode(['currency' => 'MAD', 'total_amount' => '120.00', 'client' => ['id' => $this->client->id, 'name' => 'Historical name'],
                'items' => [['product_id' => null, 'description' => 'Historical line', 'quantity' => '1.00', 'total' => '120.00']]]),
            'created_at' => now(), 'updated_at' => now()], ...$attributes]);
    }

    private function payment(int $invoice, string $amount = '20.00', array $extra = []): int
    {
        return DB::table('payments')->insertGetId(['invoice_id' => $invoice, 'amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now(), ...$extra]);
    }

    public static function roles(): array
    {
        return [['admin', 200], ['employee', 403], ['comptable', 200]];
    }

    #[DataProvider('roles')]
    public function test_financial_permissions_and_empty_dashboard(string $role, int $status): void
    {
        $this->actor->role = $role;
        $this->actor->save();
        $r = $this->getJson('/api/dashboard/financial')->assertStatus($status);
        if ($status === 403) {
            $r->assertJsonMissingPath('groups');
        } else {
            $r->assertJsonPath('groups', [])->assertJsonPath('cache', 'disabled');
        }
    }

    public function test_metrics_use_current_payment_once_and_preserve_history_and_tenant(): void
    {
        $id = $this->invoice();
        $p = $this->payment($id, '30.00', ['version' => 2]);
        $this->payment($id, '20.00');
        DB::table('payment_revisions')->insert(['payment_id' => $p, 'actor_id' => $this->actor->id, 'actor_name' => 'Actor', 'reason' => 'Correction', 'before' => json_encode(['amount' => '10.00']), 'after' => json_encode(['amount' => '30.00']), 'created_at' => now()]);
        $this->invoice(['document_status' => 'cancelled', 'status' => 'cancelled']);
        $this->invoice(['document_status' => 'draft', 'status' => 'draft', 'archived_at' => now()]);
        $other = Company::create(['name' => 'B']);
        $foreign = Client::create(['company_id' => $other->id, 'name' => 'Other']);
        $this->invoice(['company_id' => $other->id, 'client_id' => $foreign->id, 'total_amount' => '9999.99']);
        $r = $this->getJson('/api/dashboard/financial')->assertOk();
        $g = collect($r->json('groups'))->firstWhere('currency', 'MAD');
        $this->assertSame('120.00', $g['billed_total']);
        $this->assertSame('50.00', $g['collected_total']);
        $this->assertSame('70.00', $g['outstanding_total']);
        $this->assertSame('70.00', $g['overdue_total']);
        $this->assertSame(2, $g['payment_count']);
        $this->assertSame(1, $g['outstanding_count']);
        $this->assertSame('Historical name', $r->json('details.top_clients.0.label'));
        $this->assertSame('120.00', $r->json('details.top_products.0.value'));
        $this->assertSame(1, DB::table('payment_revisions')->count());
    }

    public function test_legacy_null_state_unknown_currency_zero_and_same_name_clients(): void
    {
        $second = Client::create(['company_id' => $this->actor->company_id, 'name' => 'Same name']);
        $this->invoice(['client_id' => $second->id]);
        $this->invoice();
        $legacy = $this->invoice(['document_status' => null, 'issued_snapshot' => null]);
        $this->payment($legacy, '20.00');
        $this->invoice(['total_amount' => '0.00', 'subtotal' => '0.00', 'tax_amount' => '0.00', 'issued_snapshot' => json_encode(['currency' => 'EUR', 'total_amount' => '0.00'])]);
        $r = $this->getJson('/api/dashboard/financial')->assertOk();
        $this->assertCount(2, $r->json('details.top_clients'));
        $unknown = collect($r->json('groups'))->firstWhere('currency', 'UNKNOWN');
        $this->assertNull($unknown['billed_total']);
        $this->assertFalse($unknown['consolidated']);
        $this->assertTrue($unknown['requires_review']);
        $eur = collect($r->json('groups'))->firstWhere('currency', 'EUR');
        $this->assertSame(0, $eur['outstanding_count']);
    }

    public function test_monthly_exact_decimals_and_period_does_not_change_stock(): void
    {
        $date = today()->startOfMonth()->toDateString();
        foreach (['0.10', '0.20'] as $amount) {
            $id = $this->invoice(['invoice_date' => $date, 'total_amount' => $amount, 'issued_snapshot' => json_encode(['currency' => 'MAD', 'total_amount' => $amount])]);
            $this->payment($id, $amount);
        }
        $r = $this->getJson('/api/dashboard/financial?from='.$date.'&to='.today()->toDateString())->assertOk();
        $g = $r->json('groups.0');
        $this->assertSame('0.30', $g['billed_total']);
        $this->assertSame('0.30', $g['collected_total']);
        $this->assertSame('0.30', $g['series'][0]['billed']);
        $this->assertSame('0.00', $g['outstanding_total']);
        $this->getJson('/api/dashboard/financial?from=2020-01-01&to=2020-01-31')->assertOk()->assertJsonPath('groups.0.billed_total', '0.30')->assertJsonPath('groups.0.period_billed', '0.00');
        $this->getJson('/api/dashboard/financial?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/dashboard/financial?from=2020-01-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_payment_correction_moves_month_without_counting_its_revision(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15'));
        $id = $this->invoice(['invoice_date' => '2026-01-10']);
        $payment = $this->payment($id, '30.00', ['payment_date' => '2026-01-31']);
        DB::table('payments')->where('id', $payment)->update(['amount' => '50.00', 'payment_date' => '2026-03-01', 'version' => 2]);
        DB::table('payment_revisions')->insert(['payment_id' => $payment, 'actor_id' => $this->actor->id, 'actor_name' => 'Actor', 'reason' => 'Correction', 'before' => json_encode(['amount' => '30.00', 'payment_date' => '2026-01-31']), 'after' => json_encode(['amount' => '50.00', 'payment_date' => '2026-03-01']), 'created_at' => now()]);
        $r = $this->getJson('/api/dashboard/financial?from=2026-01-01&to=2026-03-31')->assertOk();
        $this->assertSame('0.00', $r->json('groups.0.series.0.collected'));
        $this->assertSame('50.00', $r->json('groups.0.series.2.collected'));
        $this->assertSame('50.00', $r->json('groups.0.collected_total'));
        $this->assertSame('70.00', $r->json('groups.0.outstanding_total'));
    }

    public function test_currencies_are_never_added_together(): void
    {
        $mad = $this->invoice();
        $this->payment($mad, '10.00');
        $eur = $this->invoice(['issued_snapshot' => json_encode(['currency' => 'EUR', 'total_amount' => '120.00'])]);
        $this->payment($eur, '20.00');
        $r = $this->getJson('/api/dashboard/financial?currency=EUR')->assertOk();
        $groups = collect($r->json('groups'));
        $this->assertSame('110.00', $groups->firstWhere('currency', 'MAD')['outstanding_total']);
        $this->assertSame('100.00', $groups->firstWhere('currency', 'EUR')['outstanding_total']);
        $this->assertSame($eur, $r->json('details.recent_invoices.0.id'));
        $this->assertArrayNotHasKey('billed_total', $r->json());
    }

    public function test_omitting_payment_history_keeps_balances_and_financial_audit(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment($invoice, '30.00');
        DB::table('payment_revisions')->insert(['payment_id' => $payment, 'actor_id' => $this->actor->id, 'actor_name' => 'Actor', 'reason' => 'Correction', 'before' => json_encode(['amount' => '20.00']), 'after' => json_encode(['amount' => '30.00']), 'created_at' => now()]);
        $this->getJson('/api/invoices/'.$invoice.'?include_payments=0')->assertOk()->assertJsonMissingPath('payments')->assertJsonPath('paid_amount', '30.00')->assertJsonPath('balance_due', '90.00')->assertJsonPath('financial_review_required', true);
        $this->getJson('/api/invoices/'.$invoice.'?include_payments=1')->assertOk()->assertJsonCount(1, 'payments.0.revisions');
    }

    public function test_bounded_pages_search_and_cross_tenant_filter(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->invoice(['invoice_number' => 'SEARCH-'.$i, 'created_at' => '2026-01-01 00:00:00']);
        }
        $one = $this->getJson('/api/invoices?per_page=10&q=SEARCH')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('total', 30)->json();
        $two = $this->getJson('/api/invoices?per_page=10&q=SEARCH&page=2')->assertOk()->json();
        $this->assertEmpty(array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id')));
        $this->assertArrayNotHasKey('items', $one['data'][0]);
        $this->assertArrayNotHasKey('issued_snapshot', $one['data'][0]);
        $this->getJson('/api/invoices?per_page=101')->assertUnprocessable();
        $this->getJson('/api/invoices?per_page=10&client_id=999999')->assertNotFound();
        $this->getJson('/api/invoices?per_page=10&company_id=2')->assertUnprocessable();
        $this->actor->role = 'employee';
        $this->actor->save();
        $r = $this->getJson('/api/invoices?per_page=10')->assertOk();
        $this->assertArrayNotHasKey('paid_amount', $r->json('data.0'));
        $this->assertArrayNotHasKey('balance_due', $r->json('data.0'));
    }

    public function test_clients_products_quotes_and_payments_paginate_without_detailed_history(): void
    {
        Product::create(['company_id' => $this->actor->company_id, 'name' => 'Find me', 'unit_price' => '12.34']);
        $this->getJson('/api/products?per_page=25&q=Find')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/clients?per_page=25&q=Same')->assertOk()->assertJsonPath('total', 1);
        $quote = DB::table('quotes')->insertGetId(['company_id' => $this->actor->company_id, 'client_id' => $this->client->id, 'quote_number' => 'Q-1', 'quote_date' => today()->toDateString(), 'created_at' => now(), 'status' => 'accepted']);
        $this->getJson('/api/quotes?per_page=25&status=accepted')->assertOk()->assertJsonPath('data.0.id', $quote)->assertJsonMissingPath('data.0.items');
        $id = $this->invoice();
        $this->payment($id, '0.10');
        $this->payment($id, '0.20');
        $r = $this->getJson('/api/payments?per_page=1')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('totals_by_currency.0.amount', '0.30');
        $this->getJson('/api/payments?per_page=1&page=2')->assertOk()->assertJsonPath('totals_by_currency.0.amount', '0.30');
        $this->assertArrayNotHasKey('revisions', $r->json('data.0'));
        $this->getJson('/api/invoices/'.$id.'/payments?per_page=1')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_cursor_and_archives_search_literals(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Client::create(['company_id' => $this->actor->company_id, 'name' => 'Literal%_'.$i]);
        }
        $this->getJson('/api/clients?per_page=25&q=0')->assertOk()->assertJsonPath('total', 1);
        $r = $this->getJson('/api/clients?per_page=2&pagination=cursor&q=Literal%25_')->assertOk();
        $next = $r->json('next_page_url');
        $this->assertNotNull($next);
        $next = $this->getJson($next)->assertOk();
        $this->assertNotSame($r->json('data.0.id'), $next->json('data.0.id'));
        $this->client->delete();
        $this->getJson('/api/clients?per_page=25&archived=1')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_cache_cannot_republish_before_invalidation_and_callback_runs_once(): void
    {
        config(['cache.default' => 'array']);
        $key = CompanyCache::key($this->actor->company_id, CompanyCache::CLIENTS);
        Cache::forget($key);
        $calls = 0;
        $result = CompanyCache::remember($key, now()->addMinute(), function () use (&$calls) {
            $calls++;
            CompanyCache::forget($this->actor->company_id, CompanyCache::CLIENTS);

            return ['old'];
        });
        $this->assertSame(['old'], $result);
        $this->assertSame(1, $calls);
        $this->assertNull(Cache::get($key));
    }
}
