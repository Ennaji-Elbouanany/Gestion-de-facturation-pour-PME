<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Support\CompanyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $actor;

    private array $paths;

    private array $payloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Company A']);
        $this->actor = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $client = Client::create(['company_id' => $this->company->id, 'name' => 'Client A']);
        $archivedClient = Client::create(['company_id' => $this->company->id, 'name' => 'Archived A']);
        $archivedClient->delete();
        $product = Product::create(['company_id' => $this->company->id, 'name' => 'Product A', 'unit_price' => 100]);
        $invoice = $sentInvoice = $archivedInvoice = $quote = $sentQuote = $archivedQuote = null;
        foreach (['draft', 'sent', 'archive'] as $kind) {
            $document = Invoice::create(['company_id' => $this->company->id, 'client_id' => $client->id,
                'invoice_number' => 'TEST-INV-'.$kind, 'invoice_date' => '2026-11-06', 'subtotal' => 100, 'tax_amount' => 20, 'total_amount' => 120,
                'status' => $kind === 'sent' ? 'sent' : 'draft']);
            $estimate = Quote::create(['company_id' => $this->company->id, 'client_id' => $client->id,
                'quote_number' => 'TEST-QUO-'.$kind, 'quote_date' => '2026-11-06', 'total_amount' => 120,
                'status' => $kind === 'sent' ? 'sent' : 'draft']);
            $document->items()->create(['description' => 'Line', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20, 'subtotal' => 100, 'total' => 120]);
            $estimate->items()->create(['description' => 'Line', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 20, 'subtotal' => 100, 'total' => 120]);
            if ($kind === 'archive') {
                $document->archived_at = now();
                $document->save();
                $estimate->archived_at = now();
                $estimate->save();
                $archivedInvoice = $document;
                $archivedQuote = $estimate;
            } elseif ($kind === 'sent') {
                $sentInvoice = $document;
                $sentQuote = $estimate;
            } else {
                $invoice = $document;
                $quote = $estimate;
            }
        }
        $payment = Payment::create(['invoice_id' => $sentInvoice->id, 'amount' => 20, 'payment_date' => '2026-11-06', 'payment_method' => 'cash']);
        $this->paths = [
            'clients' => '/api/clients', 'client' => '/api/clients/'.$client->id,
            'clientArchive' => '/api/clients/'.$archivedClient->id,
            'products' => '/api/products', 'product' => '/api/products/'.$product->id,
            'invoices' => '/api/invoices', 'invoice' => '/api/invoices/'.$invoice->id,
            'sentInvoice' => '/api/invoices/'.$sentInvoice->id, 'archivedInvoice' => '/api/invoices/'.$archivedInvoice->id,
            'quotes' => '/api/quotes', 'quote' => '/api/quotes/'.$quote->id,
            'sentQuote' => '/api/quotes/'.$sentQuote->id, 'archivedQuote' => '/api/quotes/'.$archivedQuote->id,
            'items' => '/api/quotes/'.$quote->id.'/items', 'item' => '/api/quotes/'.$quote->id.'/items/'.$quote->items()->first()->id,
            'invoicePayments' => '/api/invoices/'.$sentInvoice->id.'/payments',
            'payment' => '/api/invoices/'.$sentInvoice->id.'/payments/'.$payment->id,
        ];
        $line = ['description' => 'New line', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0];
        $this->payloads = [
            'client' => ['name' => 'Updated client'], 'product' => ['name' => 'Updated product', 'unit_price' => 10],
            'invoice' => ['client_id' => $client->id, 'invoice_date' => '2026-11-06', 'items' => [$line]],
            'invoiceSent' => ['client_id' => $client->id, 'invoice_date' => '2026-11-06', 'items' => [$line], 'status' => 'sent'],
            'quote' => ['client_id' => $client->id, 'quote_date' => '2026-11-06', 'items' => [$line]],
            'line' => $line, 'notes' => ['notes' => 'Updated'], 'statusSent' => ['status' => 'sent'],
            'converted' => ['status' => 'converted'],
            'payment' => ['version' => 1, 'reason' => 'Correction contrôlée', 'amount' => 10, 'payment_date' => '2026-11-06', 'payment_method' => 'cash'],
            'profile' => ['name' => 'Me', 'email' => 'new@example.test'],
            'security' => ['current_password' => 'password', 'password' => 'NewSecret!2026', 'password_confirmation' => 'NewSecret!2026'],
            'company' => ['name' => 'Updated company'], 'billing' => ['currency' => 'MAD'],
            'documents' => ['invoice_prefix' => 'TEST-'], 'notifications' => ['notify_on_payment' => false],
        ];
    }

    public static function operations(): array
    {
        // method, path key (or absolute path), suffix, payload key, admin/employee/accountant HTTP statuses.
        return [
            'clients index' => ['GET', 'clients', '', null, [200, 200, 200]],
            'clients archives' => ['GET', 'clients', '?archived=1', null, [200, 200, 200]],
            'client show' => ['GET', 'client', '', null, [200, 200, 200]],
            'client create' => ['POST', 'clients', '', 'client', [201, 201, 201]],
            'client update' => ['PATCH', 'client', '', 'client', [200, 200, 200]],
            'client archive' => ['DELETE', 'client', '', null, [200, 403, 403]],
            'client restore' => ['PATCH', 'clientArchive', '/restore', null, [200, 403, 403]],
            'products index' => ['GET', 'products', '', null, [200, 200, 200]],
            'product show' => ['GET', 'product', '', null, [200, 200, 200]],
            'product create' => ['POST', 'products', '', 'product', [201, 403, 201]],
            'product update' => ['PATCH', 'product', '', 'product', [200, 403, 200]],
            'product delete' => ['DELETE', 'product', '', null, [200, 403, 403]],
            'invoices index' => ['GET', 'invoices', '', null, [200, 200, 200]],
            'invoice show' => ['GET', 'sentInvoice', '', null, [200, 200, 200]],
            'invoice create draft' => ['POST', 'invoices', '', 'invoice', [201, 201, 201]],
            'invoice create sent' => ['POST', 'invoices', '', 'invoiceSent', [201, 403, 201]],
            'invoice edit draft' => ['PATCH', 'invoice', '', 'notes', [200, 200, 200]],
            'invoice edit issued' => ['PATCH', 'sentInvoice', '', 'notes', [409, 403, 409]],
            'invoice status' => ['PATCH', 'invoice', '/status', 'statusSent', [200, 403, 200]],
            'invoice status via patch' => ['PATCH', 'invoice', '', 'statusSent', [200, 403, 200]],
            'invoice status via put' => ['PUT', 'invoice', '', 'statusSent', [200, 403, 200]],
            'invoice archive' => ['PATCH', 'invoice', '/archive', null, [200, 200, 200]],
            'invoice restore' => ['PATCH', 'archivedInvoice', '/restore', null, [200, 200, 200]],
            'invoice edit archived' => ['PATCH', 'archivedInvoice', '', 'notes', [409, 409, 409]],
            'invoice archive issued' => ['PATCH', 'sentInvoice', '/archive', null, [409, 409, 409]],
            'invoice physical delete' => ['DELETE', 'invoice', '', null, [409, 409, 409]],
            'quotes index' => ['GET', 'quotes', '', null, [200, 200, 200]],
            'quote show' => ['GET', 'quote', '', null, [200, 200, 200]],
            'quote create' => ['POST', 'quotes', '', 'quote', [201, 201, 403]],
            'quote update' => ['PATCH', 'quote', '', 'notes', [200, 200, 403]],
            'quote status' => ['PATCH', 'quote', '/status', 'statusSent', [200, 200, 403]],
            'quote forged conversion status' => ['PATCH', 'quote', '/status', 'converted', [200, 403, 403]],
            'quote forged conversion patch' => ['PATCH', 'quote', '', 'converted', [200, 403, 403]],
            'quote convert' => ['POST', 'quote', '/convert', null, [201, 403, 201]],
            'quote archive' => ['PATCH', 'quote', '/archive', null, [200, 200, 403]],
            'quote restore' => ['PATCH', 'archivedQuote', '/restore', null, [200, 200, 403]],
            'quote edit archived' => ['PATCH', 'archivedQuote', '', 'notes', [409, 409, 403]],
            'quote physical delete' => ['DELETE', 'quote', '', null, [409, 409, 409]],
            'quote items index' => ['GET', 'items', '', null, [200, 200, 200]],
            'quote item show' => ['GET', 'item', '', null, [200, 200, 200]],
            'quote item create' => ['POST', 'items', '', 'line', [201, 201, 403]],
            'quote item update' => ['PATCH', 'item', '', 'line', [200, 200, 403]],
            'quote item delete' => ['DELETE', 'item', '', null, [200, 200, 403]],
            'payments all' => ['GET', '/api/payments', '', null, [200, 403, 200]],
            'payments index' => ['GET', 'invoicePayments', '', null, [200, 403, 200]],
            'payment show' => ['GET', 'payment', '', null, [200, 403, 200]],
            'payment create' => ['POST', 'invoicePayments', '', 'payment', [201, 403, 201]],
            'payment update' => ['PATCH', 'payment', '', 'payment', [200, 403, 200]],
            'payment physical delete' => ['DELETE', 'payment', '', null, [409, 403, 409]],
            'settings read' => ['GET', '/api/settings', '', null, [200, 200, 200]],
            'settings company' => ['PATCH', '/api/settings/company', '', 'company', [200, 403, 403]],
            'settings billing' => ['PATCH', '/api/settings/billing', '', 'billing', [200, 403, 200]],
            'settings documents' => ['PATCH', '/api/settings/documents', '', 'documents', [200, 403, 403]],
            'settings notifications' => ['PATCH', '/api/settings/notifications', '', 'notifications', [200, 403, 403]],
            'own profile' => ['PATCH', '/api/settings/profile', '', 'profile', [200, 200, 200]],
            'own password' => ['PATCH', '/api/settings/security', '', 'security', [200, 200, 200]],
            'dashboard' => ['GET', '/api/dashboard/summary', '', null, [200, 200, 200]],
            'user permissions' => ['GET', '/api/user', '', null, [200, 200, 200]],
        ];
    }

    public static function roleOperations(): iterable
    {
        foreach (['admin', 'employee', 'comptable'] as $index => $role) {
            foreach (self::operations() as $name => [$method, $path, $suffix, $payload, $statuses]) {
                yield $role.' '.$name => [$role, $method, $path, $suffix, $payload, $statuses[$index]];
            }
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['users', 'companies', 'clients', 'products', 'invoices', 'quotes', 'invoice_items', 'quote_items', 'payments'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $result;
    }

    #[DataProvider('roleOperations')]
    public function test_role_matrix(string $role, string $method, string $path, string $suffix, ?string $payload, int $status): void
    {
        $this->actor->role = $role;
        $this->actor->save();
        $this->actingAs($this->actor);
        $before = $this->snapshot();
        $key = CompanyCache::key($this->company->id, CompanyCache::DASHBOARD);
        Cache::put($key, ['clients_count' => 1, 'products_count' => 1, 'invoices_count' => 2, 'quotes_count' => 2, 'payments_count' => 1], 60);
        $cached = Cache::get($key);
        $response = $this->json($method, ($this->paths[$path] ?? $path).$suffix, $payload ? $this->payloads[$payload] : []);
        $response->assertStatus($status);
        if ($status >= 400) {
            $this->assertEquals($before, $this->snapshot(), 'A refused operation must not change data.');
            $this->assertSame($cached, Cache::get($key), 'A refused operation must not invalidate the company cache.');
        }
        if ($path === 'sentInvoice' && $method === 'GET') {
            $role === 'employee' ? $response->assertJsonMissingPath('payments') : $response->assertJsonCount(1, 'payments');
        }
        if ($path === '/api/dashboard/summary') {
            $role === 'employee' ? $response->assertJsonMissingPath('payments_count') : $response->assertJsonPath('payments_count', 1);
            $this->assertSame($cached, Cache::get($key), 'Redaction must not mutate shared cached data.');
        }
        if ($path === '/api/user') {
            $this->assertSame($role !== 'employee', $response->json('permissions')['payments.view']);
            $this->assertSame($role !== 'employee', $response->json('permissions')['dashboard.financial']);
        }
    }

    public static function roles(): array
    {
        return [['admin'], ['employee'], ['comptable']];
    }

    #[DataProvider('roles')]
    public function test_cross_company_requests_are_hidden_for_every_role(string $role): void
    {
        $foreign = Company::create(['name' => 'Company B']);
        $actor = User::factory()->create(['company_id' => $foreign->id, 'role' => $role]);
        $this->actingAs($actor);
        $before = $this->snapshot();
        foreach (self::operations() as [$method, $path, $suffix, $payload]) {
            if (! isset($this->paths[$path]) || in_array($path, ['clients', 'products', 'invoices', 'quotes'], true)) {
                continue;
            }
            $this->json($method, $this->paths[$path].$suffix, $payload ? $this->payloads[$payload] : [])->assertNotFound();
        }
        $this->assertEquals($before, $this->snapshot());
        foreach ([Client::class, Product::class, Invoice::class, Quote::class, Payment::class] as $model) {
            $record = $model === Client::class ? $model::withTrashed()->firstOrFail() : $model::firstOrFail();
            $this->assertSame(404, Gate::forUser($actor)->inspect('view', $record)->status());
        }
        $this->assertSame(404, Gate::forUser($actor)->inspect('update', $this->company)->status());
        $this->assertSame(404, Gate::forUser($actor)->inspect('update', $this->actor)->status());
        foreach (['clients', 'products', 'invoices', 'quotes'] as $resource) {
            $this->getJson('/api/'.$resource)->assertExactJson([]);
        }
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        foreach (self::operations() as [$method, $path, $suffix, $payload]) {
            $this->json($method, ($this->paths[$path] ?? $path).$suffix, $payload ? $this->payloads[$payload] : [])->assertUnauthorized();
        }
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_nested_resources_cannot_be_moved_between_parents(): void
    {
        $this->actingAs($this->actor);
        $paymentId = Payment::firstOrFail()->id;
        $wrongPayment = $this->paths['invoice'].'/payments/'.$paymentId;
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $wrongPayment, [])->assertNotFound();
        }
        $line = Quote::where('status', 'sent')->firstOrFail()->items()->firstOrFail();
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, $this->paths['items'].'/'.$line->id, [])->assertNotFound();
        }
    }

    public function test_role_and_tenant_changes_are_rejected_on_account_endpoints(): void
    {
        $this->actingAs($this->actor);
        $before = $this->snapshot();
        foreach (['role' => 'employee', 'company_id' => 12345, 'permissions' => ['payments.view' => true]] as $field => $value) {
            foreach (['profile', 'security', 'company', 'billing', 'documents', 'notifications'] as $section) {
                $this->patchJson('/api/settings/'.$section, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        $this->assertEquals($before, $this->snapshot());
        $this->actor->fill(['role' => 'employee', 'company_id' => 12345])->save();
        $this->assertSame('admin', $this->actor->fresh()->role);
        $this->assertSame($this->company->id, $this->actor->fresh()->company_id);
    }

    public function test_registration_assigns_only_server_owned_administrator_role(): void
    {
        $payload = ['name' => 'Founder', 'email' => 'founder@example.test', 'company_name' => 'New company',
            'password' => 'FounderSecret!2026', 'password_confirmation' => 'FounderSecret!2026'];
        foreach ([['role' => 'admin'], ['role' => 'employee'], ['role' => 'comptable'],
            ['company_id' => $this->company->id], ['permissions' => ['admin' => true]]] as $attack) {
            $this->postJson('/api/register', array_merge($payload, $attack))->assertUnprocessable();
        }
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('users', 1);
        // Avoid sharing the login throttle key with the five rejected requests.
        $payload['email'] = 'legitimate@example.test';
        $created = $this->postJson('/api/register', $payload)->assertCreated()->assertJsonPath('user.role', 'admin')->json();
        $this->assertNotEquals($this->company->id, $created['user']['company_id']);
        $this->assertNotEmpty($created['token']);
        $this->assertSame('admin', $this->actor->fresh()->role);
    }

    public function test_existing_token_uses_the_current_database_role(): void
    {
        $token = $this->actor->createToken('rbac-test', ['*'])->plainTextToken;
        $this->withToken($token)->getJson('/api/payments')->assertOk();
        $this->actor->role = 'employee';
        $this->actor->save();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/payments')->assertForbidden();
        $profile = $this->getJson('/api/user')->assertOk();
        $this->assertFalse($profile->json('permissions')['payments.view']);
    }

    public function test_missing_company_and_unknown_roles_have_no_business_access(): void
    {
        $orphan = User::factory()->create(['company_id' => null, 'role' => 'admin']);
        $this->actingAs($orphan);
        $this->getJson('/api/clients')->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
        $this->getJson('/api/payments')->assertForbidden();
        $this->getJson('/api/dashboard/summary')->assertForbidden();
        // Invalid enum values cannot normally be stored on MySQL; test policy denial in memory.
        $this->actor->role = 'unexpected';
        $this->assertFalse(Gate::forUser($this->actor)->allows('viewAny', Invoice::class));
    }

    public function test_employee_summary_is_an_allowlist_even_when_shared_cache_contains_financial_metrics(): void
    {
        $this->actor->role = 'employee';
        $this->actor->save();
        $this->actingAs($this->actor);
        $cached = ['clients_count' => 1, 'products_count' => 1, 'invoices_count' => 2, 'quotes_count' => 2,
            'payments_count' => 1, 'revenue' => 10000, 'collected' => 5000];
        $key = CompanyCache::key($this->company->id, CompanyCache::DASHBOARD);
        Cache::put($key, $cached, 60);
        $this->getJson('/api/dashboard/summary')->assertExactJson([
            'clients_count' => 1, 'products_count' => 1, 'invoices_count' => 2, 'quotes_count' => 2,
        ]);
        $this->assertSame($cached, Cache::get($key));
    }

    public function test_employee_can_preserve_existing_converted_status_without_forging_a_conversion(): void
    {
        $quote = Quote::where('status', 'sent')->firstOrFail();
        $quote->status = 'converted';
        $quote->save();
        $this->actor->role = 'employee';
        $this->actor->save();
        $this->actingAs($this->actor);
        $this->patchJson('/api/quotes/'.$quote->id, ['status' => 'converted', 'notes' => 'Same status'])
            ->assertOk()->assertJsonPath('status', 'converted');
        $this->postJson('/api/quotes/'.$quote->id.'/convert')->assertForbidden();
    }

    public function test_account_audit_reports_missing_admins_without_changing_users(): void
    {
        $this->artisan('rbac:audit-accounts')->assertSuccessful();
        $company = Company::create(['name' => 'No admin']);
        User::factory()->create(['company_id' => $company->id, 'role' => 'employee']);
        $before = $this->snapshot();
        $this->artisan('rbac:audit-accounts')->assertFailed();
        $this->assertEquals($before, $this->snapshot());
    }
}
