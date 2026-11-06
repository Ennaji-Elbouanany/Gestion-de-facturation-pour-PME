<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\CompanyCache;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FinancialPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Finance PME', 'currency' => 'MAD']);
        $this->actor = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $this->client = Client::create(['company_id' => $company->id, 'name' => 'Original client']);
        $this->actingAs($this->actor);
    }

    private function invoice(bool $issue = true, array $line = []): array
    {
        return $this->postJson('/api/invoices', ['client_id' => $this->client->id, 'invoice_date' => today()->toDateString(),
            'status' => $issue ? 'sent' : 'draft', 'items' => [array_merge(['description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '0'], $line)]])
            ->assertCreated()->json();
    }

    private function data(string $amount = '30.00'): array
    {
        return ['amount' => $amount, 'payment_date' => today()->toDateString(), 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_partial_total_overpayment_and_authoritative_balance(): void
    {
        $invoice = $this->invoice();
        $url = '/api/invoices/'.$invoice['id'];
        $this->postJson($url.'/payments', $this->data())->assertCreated();
        $this->getJson($url)->assertJsonPath('paid_amount', '30.00')->assertJsonPath('balance_due', '70.00')->assertJsonPath('payment_status', 'partial');
        $this->postJson($url.'/payments', $this->data('70.01'))->assertUnprocessable();
        $this->postJson($url.'/payments', $this->data('70.00'))->assertCreated();
        $this->getJson($url)->assertJsonPath('status', 'paid')->assertJsonPath('balance_due', '0.00');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_decimal_precision_and_bounds_are_enforced(): void
    {
        $invoice = $this->invoice();
        foreach (['0', '-1', '0.001', '1.005', '1e1', '10000000000.00'] as $amount) {
            $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data($amount))->assertUnprocessable();
        }
        $this->assertDatabaseCount('payments', 0);
        $small = $this->invoice(true, ['quantity' => '0.50', 'unit_price' => '0.03', 'tax_rate' => '20']);
        $this->assertSame('0.02', $small['total_amount']);
        $this->postJson('/api/invoices/'.$small['id'].'/payments', $this->data('0.02'))->assertCreated();
        $this->getJson('/api/invoices/'.$small['id'])->assertJsonPath('payment_status', 'paid');
    }

    public function test_idempotency_retries_do_not_duplicate_and_mismatches_conflict(): void
    {
        $invoice = $this->invoice();
        $url = '/api/invoices/'.$invoice['id'].'/payments';
        $data = $this->data();
        $id = $this->postJson($url, $data)->assertCreated()->json('id');
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('id', $id);
        $this->postJson($url, array_replace($data, ['amount' => '40.00']))->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_correction_is_versioned_motivated_and_recalculates_status(): void
    {
        $invoice = $this->invoice();
        $url = '/api/invoices/'.$invoice['id'];
        $payment = $this->postJson($url.'/payments', $this->data('100.00'))->assertCreated()->json();
        $paymentUrl = $url.'/payments/'.$payment['id'];
        $this->patchJson($paymentUrl, ['amount' => '20.00'])->assertUnprocessable();
        $this->patchJson($paymentUrl, ['amount' => '20.00', 'version' => 1, 'reason' => 'Correction du montant'])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('revisions.0.before.amount', '100.00')->assertJsonPath('revisions.0.after.amount', '20.00');
        $this->patchJson($paymentUrl, ['amount' => '10.00', 'version' => 1, 'reason' => 'Ancienne version'])->assertStatus(409);
        $this->getJson($url)->assertJsonPath('payment_status', 'partial')->assertJsonPath('balance_due', '80.00');
        $this->assertDatabaseCount('payment_revisions', 1);
    }

    public static function financialStatuses(): array
    {
        return [['paid'], ['partial'], ['overdue']];
    }

    #[DataProvider('financialStatuses')]
    public function test_financial_statuses_cannot_be_forged_through_any_route(string $status): void
    {
        $invoice = $this->invoice(false);
        $url = '/api/invoices/'.$invoice['id'];
        foreach (['PUT', 'PATCH'] as $method) {
            $this->json($method, $url, ['status' => $status])->assertUnprocessable();
        }
        $this->patchJson($url.'/status', ['status' => $status])->assertUnprocessable();
        $this->postJson('/api/invoices', ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'draft']);
    }

    public function test_drafts_archives_and_cancelled_documents_do_not_accept_payments(): void
    {
        $draft = $this->invoice(false);
        $draftUrl = '/api/invoices/'.$draft['id'];
        $this->postJson($draftUrl.'/payments', $this->data())->assertStatus(409);
        $this->patchJson($draftUrl.'/archive')->assertOk();
        $this->postJson($draftUrl.'/payments', $this->data())->assertStatus(409);
        $issued = $this->invoice();
        $url = '/api/invoices/'.$issued['id'];
        $this->patchJson($url.'/status', ['status' => 'cancelled'])->assertOk();
        $this->postJson($url.'/payments', $this->data())->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'sent'])->assertStatus(409);
    }

    public function test_emitted_content_is_frozen_before_and_after_payment(): void
    {
        $invoice = $this->invoice();
        $url = '/api/invoices/'.$invoice['id'];
        foreach ([['client_id' => $this->client->id], ['notes' => 'Changed'], ['items' => [['description' => 'Reduced', 'quantity' => 1, 'unit_price' => 1]]]] as $payload) {
            $this->patchJson($url, $payload)->assertStatus(409);
        }
        $this->patchJson($url.'/status', ['status' => 'draft'])->assertStatus(409);
        $this->postJson($url.'/payments', $this->data('100.00'))->assertCreated();
        $this->patchJson($url, ['notes' => 'Paid edit'])->assertStatus(409);
        $this->patchJson($url.'/status', ['status' => 'cancelled'])->assertStatus(409);
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'total_amount' => 100]);
    }

    public function test_snapshot_preserves_client_company_and_lines_after_master_data_changes(): void
    {
        $invoice = $this->invoice();
        $this->client->update(['name' => 'Renamed client']);
        Company::findOrFail($this->actor->company_id)->update(['name' => 'Renamed company']);
        $this->getJson('/api/invoices/'.$invoice['id'])->assertJsonPath('client.name', 'Original client')
            ->assertJsonPath('issued_snapshot.company.name', 'Finance PME')->assertJsonPath('items.0.description', 'Service');
    }

    public function test_error_rolls_back_payment_correction_revision_status_and_cache(): void
    {
        $invoice = $this->invoice();
        $payment = $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data())->assertCreated()->json();
        $key = CompanyCache::key($this->actor->company_id, CompanyCache::DASHBOARD);
        Cache::put($key, ['marker' => true], 60);
        // Fail with an actual SQL error after all financial writes, before outer commit.
        try {
            DB::transaction(function () use ($invoice, $payment): void {
                app(PaymentService::class)->update($this->actor, $invoice['id'], $payment['id'], ['amount' => '60.00', 'version' => 1, 'reason' => 'Rollback test']);
                DB::statement('INSERT INTO missing_financial_test_table (id) VALUES (1)');
            });
            $this->fail('SQL failure expected');
        } catch (QueryException $exception) {
        }
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'amount' => 30, 'version' => 1]);
        $this->assertDatabaseCount('payment_revisions', 0);
        $this->assertSame(['marker' => true], Cache::get($key));
        $this->getJson('/api/invoices/'.$invoice['id'])->assertJsonPath('paid_amount', '30.00');
    }

    public function test_success_invalidates_cache_and_old_financial_list_cache_is_not_served(): void
    {
        $invoice = $this->invoice();
        $key = CompanyCache::key($this->actor->company_id, CompanyCache::DASHBOARD);
        Cache::put($key, ['marker' => true], 60);
        Cache::put(CompanyCache::key($this->actor->company_id, CompanyCache::INVOICES), [['status' => 'paid']], 60);
        $this->getJson('/api/invoices')->assertJsonPath('0.status', 'sent');
        $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data())->assertCreated();
        $this->assertFalse(Cache::has($key));
    }

    public function test_payment_update_is_blocked_on_legacy_cancelled_invoice(): void
    {
        $invoice = $this->invoice();
        $payment = $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data())->assertCreated()->json();
        DB::table('invoices')->where('id', $invoice['id'])->update(['document_status' => 'cancelled', 'status' => 'cancelled']);
        $this->patchJson('/api/invoices/'.$invoice['id'].'/payments/'.$payment['id'], ['amount' => '10.00', 'version' => 1, 'reason' => 'Legacy correction'])->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $payment['id'], 'amount' => 30]);
    }

    public function test_zero_total_needs_no_payment_and_cache_outage_does_not_revert_money(): void
    {
        $zero = $this->invoice(true, ['unit_price' => '0.00']);
        $this->getJson('/api/invoices/'.$zero['id'])->assertJsonPath('payment_status', 'not_required');
        $this->postJson('/api/invoices/'.$zero['id'].'/payments', $this->data('0.01'))->assertUnprocessable();
        $invoice = $this->invoice();
        Cache::partialMock()->shouldReceive('forget')->andThrow(new RuntimeException('Test cache unavailable'));
        $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data())->assertCreated();
        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice['id'], 'amount' => 30]);
    }

    public function test_financial_audit_never_repairs_legacy_data(): void
    {
        $invoice = $this->invoice(false);
        DB::table('invoices')->where('id', $invoice['id'])->update(['status' => 'paid', 'document_status' => null]);
        $before = DB::table('invoices')->where('id', $invoice['id'])->first();
        $this->artisan('billing:audit')->assertFailed();
        $this->assertEquals($before, DB::table('invoices')->where('id', $invoice['id'])->first());
        $this->postJson('/api/invoices/'.$invoice['id'].'/payments', $this->data())->assertStatus(409);
    }
}
