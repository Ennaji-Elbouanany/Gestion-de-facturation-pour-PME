<?php

use App\Models\User;
use App\Services\FinancialTransaction;
use App\Support\CompanyCache;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$c = DB::connection();
if (! app()->environment('testing') || $c->getDriverName() !== 'mysql' || $c->getConfig('host') !== '127.0.0.1' || ! str_ends_with($c->getDatabaseName(), '_test')) {
    exit(2);
}
function out($data)
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}
$action = $argv[1] ?? '';
if (! in_array($action, ['seed', 'bench', 'plans', 'race'], true)) {
    fwrite(STDERR, 'Allowed: seed, bench, plans, race; local *_test databases only.');
    exit(2);
}
if ($action === 'seed') {
    if (DB::table('companies')->exists()) {
        throw new RuntimeException('Refuse existing data');
    }
    DB::transaction(function () {
        for ($co = 1; $co <= 20; $co++) {
            DB::table('companies')->insert(['id' => $co, 'name' => "Synthetic company $co", 'currency' => 'MAD']);
            DB::table('users')->insert(['id' => $co, 'company_id' => $co, 'name' => 'Synthetic admin', 'email' => "admin$co@example.test", 'password' => password_hash('FixtureOnly!2026', PASSWORD_BCRYPT, ['cost' => 4]), 'role' => 'admin']);
            $clients = $products = [];
            for ($i = 1; $i <= 100; $i++) {
                $clients[] = ['id' => ($co - 1) * 100 + $i, 'company_id' => $co, 'name' => "Synthetic client $i", 'email' => "client$i@example.test", 'deleted_at' => $i % 10 === 0 ? '2026-10-01 00:00:00' : null, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'];
            }
            for ($i = 1; $i <= 50; $i++) {
                $products[] = ['id' => ($co - 1) * 50 + $i, 'company_id' => $co, 'name' => "Synthetic product $i", 'unit_price' => '50.00', 'tax_rate' => '20.00', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00'];
            }
            DB::table('clients')->insert($clients);
            DB::table('products')->insert($products);
            $invoices = $items = $payments = $revisions = [];
            for ($i = 1; $i <= 500; $i++) {
                $id = ($co - 1) * 500 + $i;
                $client = ($co - 1) * 100 + (($i - 1) % 90) + 1;
                $product = ($co - 1) * 50 + (($i - 1) % 50) + 1;
                $date = (new DateTimeImmutable('2025-01-01'))->modify('+'.($i % 24).' months')->modify('+'.($i % 25).' days')->format('Y-m-d');
                $state = $i % 10 === 0 ? 'draft' : ($i % 10 === 1 ? 'cancelled' : 'issued');
                $amounts = $state === 'issued' ? ($i % 5 === 4 ? ['120.00'] : ($i % 3 === 0 ? [] : ($i % 3 === 1 ? ['40.00'] : ['30.00', '50.00']))) : [];
                $paid = array_sum(array_map(fn ($a) => (int) $a, $amounts));
                $status = $state === 'issued' ? ($paid === 120 ? 'paid' : ($paid > 0 ? 'partial' : 'sent')) : ($state === 'draft' ? 'draft' : 'cancelled');
                $legacy = $state === 'issued' && $i % 13 === 0;
                $line = ['product_id' => $product, 'description' => 'Synthetic line', 'quantity' => '1.00', 'unit_price' => '50.00', 'tax_rate' => '20.00', 'subtotal' => '50.00', 'total' => '60.00'];
                $snapshot = $state === 'issued' && ! $legacy ? json_encode(['source' => 'at_issuance', 'currency' => 'MAD', 'company' => ['id' => $co, 'name' => "Synthetic company $co"], 'client' => ['id' => $client, 'name' => 'Synthetic client '.(($i - 1) % 90 + 1)], 'items' => [$line, $line], 'subtotal' => '100.00', 'tax_amount' => '20.00', 'total_amount' => '120.00']) : null;
                $invoices[] = ['id' => $id, 'company_id' => $co, 'client_id' => $client, 'user_id' => $co, 'invoice_number' => "TEST-$id", 'invoice_date' => $date, 'due_date' => (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d'), 'subtotal' => '100.00', 'tax_amount' => '20.00', 'discount_amount' => '0.00', 'total_amount' => '120.00', 'status' => $status, 'document_status' => $legacy ? null : $state, 'issued_at' => $state === 'issued' ? $date.' 12:00:00' : null, 'issued_snapshot' => $snapshot, 'archived_at' => $state === 'draft' && $i % 20 === 0 ? '2026-10-01 00:00:00' : null, 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
                for ($j = 0; $j < 2; $j++) {
                    $items[] = ['invoice_id' => $id, ...$line, 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
                }
                foreach ($amounts as $j => $amount) {
                    $pid = $id * 2 + $j;
                    $payments[] = ['id' => $pid, 'invoice_id' => $id, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => 'bank_transfer', 'version' => $i % 7 === 0 ? 2 : 1, 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
                    if ($i % 7 === 0) {
                        $revisions[] = ['payment_id' => $pid, 'actor_id' => $co, 'actor_name' => 'Synthetic admin', 'reason' => 'Synthetic correction', 'before' => json_encode(['amount' => '20.00', 'version' => 1]), 'after' => json_encode(['amount' => $amount, 'version' => 2]), 'created_at' => $date.' 12:00:00'];
                    }
                }
            }
            foreach (array_chunk($invoices, 100) as $chunk) {
                DB::table('invoices')->insert($chunk);
            }
            foreach (array_chunk($items, 300) as $chunk) {
                DB::table('invoice_items')->insert($chunk);
            }
            foreach (array_chunk($payments, 300) as $chunk) {
                DB::table('payments')->insert($chunk);
            }
            foreach (array_chunk($revisions, 300) as $chunk) {
                DB::table('payment_revisions')->insert($chunk);
            }
            $quotes = $qi = [];
            $states = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];
            for ($i = 1; $i <= 250; $i++) {
                $id = ($co - 1) * 250 + $i;
                $date = (new DateTimeImmutable('2025-01-01'))->modify('+'.($i % 24).' months')->format('Y-m-d');
                $status = $states[$i % 6];
                $quotes[] = ['id' => $id, 'company_id' => $co, 'client_id' => ($co - 1) * 100 + (($i - 1) % 90) + 1, 'user_id' => $co, 'quote_number' => "Q-$id", 'quote_date' => $date, 'status' => $status, 'subtotal' => '100.00', 'tax_amount' => '20.00', 'total_amount' => '120.00', 'archived_at' => $status === 'draft' && $i % 12 === 0 ? '2026-10-01 00:00:00' : null, 'created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'];
                for ($j = 0; $j < 2; $j++) {
                    $qi[] = ['quote_id' => $id, 'product_id' => ($co - 1) * 50 + 1, 'description' => 'Synthetic line', 'quantity' => '1.00', 'unit_price' => '50.00', 'tax_rate' => '20.00', 'subtotal' => '50.00', 'total' => '60.00'];
                }
            }
            DB::table('quotes')->insert($quotes);
            foreach (array_chunk($qi, 300) as $chunk) {
                DB::table('quote_items')->insert($chunk);
            }
        }
    });
    $counts = [];
    foreach (['companies', 'clients', 'products', 'invoices', 'invoice_items', 'quotes', 'quote_items', 'payments', 'payment_revisions'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    out(['counts' => $counts, 'mysql' => DB::selectOne('SELECT VERSION() AS v')->v, 'php' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit')]);
}
if ($action === 'bench') {
    $path = $argv[2];
    $mode = $argv[3];
    config(['cache.stores.audit_none' => ['driver' => 'null']]);
    Cache::setDefaultDriver($mode === 'null' ? 'audit_none' : 'redis');
    $counter = 0;
    $sqlms = 0;
    DB::listen(function ($query) use (&$counter, &$sqlms) {
        $counter++;
        $sqlms += $query->time;
    });
    $keys = array_map(fn ($res) => CompanyCache::key(1, $res), [CompanyCache::CLIENTS, CompanyCache::PRODUCTS, CompanyCache::QUOTES, CompanyCache::DASHBOARD]);
    $samples = [];
    for ($n = 0; $n < 6; $n++) {
        if ($mode === 'miss') {
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
        Auth::forgetGuards();
        Auth::setUser(User::findOrFail(1));
        $counter = 0;
        $sqlms = 0;
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }$mem = memory_get_usage(false);
        $start = hrtime(true);
        $request = Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $kernel = app(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $start) / 1e6;
        $bytes = strlen($response->getContent());
        $delta = memory_get_peak_usage(false) - $mem;
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Non-200 benchmark response '.$response->getStatusCode());
        }
        if ($n > 0) {
            $samples[] = ['ms' => $ms, 'queries' => $counter, 'sql_ms' => $sqlms, 'bytes' => $bytes, 'peak_delta_mb' => $delta / 1048576];
        }
        $kernel->terminate($request, $response);
        unset($response,$request);
        gc_collect_cycles();
    }
    $med = function ($key) use ($samples) {
        $vals = array_column($samples, $key);
        sort($vals);

        return round($vals[2], 3);
    };
    out(['path' => $path, 'mode' => $mode, 'runs' => 5, 'median_ms' => $med('ms'), 'queries' => $med('queries'), 'sql_ms' => $med('sql_ms'), 'bytes' => $med('bytes'), 'peak_delta_mb' => $med('peak_delta_mb')]);
}
if ($action === 'plans') {
    $queries = [
        'clients_page' => 'SELECT * FROM clients WHERE company_id=1 AND deleted_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 25',
        'products_page' => 'SELECT * FROM products WHERE company_id=1 ORDER BY created_at DESC,id DESC LIMIT 25',
        'invoices_page' => 'SELECT * FROM invoices WHERE company_id=1 AND archived_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 25',
        'quotes_page' => 'SELECT * FROM quotes WHERE company_id=1 AND archived_at IS NULL ORDER BY created_at DESC,id DESC LIMIT 25',
        'invoice_period' => "SELECT id,invoice_date,total_amount FROM invoices WHERE company_id=1 AND invoice_date>='2026-01-01' AND invoice_date<'2026-04-01' AND (document_status='issued' OR (document_status IS NULL AND status NOT IN ('draft','cancelled')))",
        'invoice_payments' => 'SELECT * FROM payments WHERE invoice_id=2 ORDER BY payment_date DESC,id DESC LIMIT 25',
        'company_payments' => 'SELECT p.* FROM payments p WHERE EXISTS (SELECT 1 FROM invoices i WHERE i.id=p.invoice_id AND i.company_id=1) ORDER BY p.payment_date DESC,p.created_at DESC,p.id DESC LIMIT 25',
    ];
    foreach ($queries as $name => $sql) {
        $plan = DB::select('EXPLAIN '.$sql);
        $analyze = DB::select('EXPLAIN ANALYZE '.$sql);
        $times = [];
        for ($i = 0; $i < 6; $i++) {
            $t = hrtime(true);
            DB::select($sql);
            if ($i > 0) {
                $times[] = (hrtime(true) - $t) / 1e6;
            }
        }sort($times);
        out(['stage' => $argv[2] ?? 'baseline', 'query' => $name, 'plan' => $plan, 'analyze' => $analyze, 'median_ms' => round($times[2], 3)]);
    }
    out(['indexes' => array_map(fn ($t) => ['table' => $t, 'indexes' => Schema::getIndexes($t)], ['clients', 'products', 'invoices', 'quotes', 'payments'])]);
}
if ($action === 'race') {
    Cache::setDefaultDriver('redis');
    $key = CompanyCache::key(1, CompanyCache::CLIENTS);
    Cache::forget($key);
    $value = CompanyCache::remember($key, now()->addMinutes(5), function () {
        $before = DB::table('clients')->where('company_id', 1)->whereNull('deleted_at')->count();
        FinancialTransaction::run(function () {
            DB::table('clients')->insert(['company_id' => 1, 'name' => 'Synthetic race probe']);
            FinancialTransaction::invalidate(1, CompanyCache::CLIENTS);
        });

        return $before;
    });
    out(['interleaving' => 'read,commit,invalidate,publish', 'cached' => Cache::get($key), 'actual' => DB::table('clients')->where('company_id', 1)->whereNull('deleted_at')->count()]);
}
