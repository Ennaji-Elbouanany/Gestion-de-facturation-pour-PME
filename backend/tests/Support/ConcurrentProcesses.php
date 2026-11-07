<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

trait ConcurrentProcesses
{
    private function runConcurrent(array $operations, callable $lock): array
    {
        $connection = DB::connection();
        $env = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $connection->getConfig('host'),
            'DB_PORT' => (string) $connection->getConfig('port'), 'DB_DATABASE' => $connection->getDatabaseName(),
            'DB_USERNAME' => $connection->getConfig('username'), 'DB_PASSWORD' => $connection->getConfig('password'),
            'DB_URL' => 'null', 'MYSQL_ATTR_SSL_CA' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr'];
        $processes = $inputs = [];
        DB::beginTransaction();
        try {
            $lock();
            foreach ($operations as $operation) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, '-r', self::workerCode(), (string) $this->actor->id, json_encode($operation)], base_path(), $env);
                $process->setInput($input);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
                $inputs[] = $input;
            }
            $this->awaitWorkers($processes, 'READY');
            foreach ($inputs as $input) {
                $input->write("GO\n");
                $input->close();
            }
            $this->awaitWorkers($processes, 'ATTEMPT');
            usleep(300000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Worker bypassed the parent row lock.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            // Pipes provide synchronization; no source or temporary file is ever deleted.
        }
    }

    private function awaitWorkers(array $processes, string $marker): void
    {
        $deadline = microtime(true) + 15;
        while (true) {
            $all = true;
            foreach ($processes as $process) {
                if (! str_contains($process->getOutput(), $marker."\n")) {
                    $all = false;
                    if (! $process->isRunning()) {
                        $this->fail('Worker exited before '.$marker.': '.$process->getErrorOutput().$process->getOutput());
                    }
                }
            }
            if ($all) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Workers did not reach '.$marker);
            }
            usleep(10000);
        }
    }

    private static function workerCode(): string
    {
        return <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = $app['db']->connection();
if (!app()->environment('testing') || $connection->getDriverName() !== 'mysql' || !str_ends_with($connection->getDatabaseName(), '_test') || !in_array($connection->getConfig('host'), ['127.0.0.1','localhost','::1'], true)) exit(2);
$actor = App\Models\User::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::setUser($actor);
$op = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
echo "READY\n"; fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') exit(3);
echo "ATTEMPT\n"; fflush(STDOUT);
try {
    if ($op[0] === 'payment') {
        [$kind, $invoiceId, $paymentId, $amount] = $op;
        $service = app(App\Services\PaymentService::class);
        $result = $paymentId === -1 ? app(App\Services\InvoiceService::class)->transition($actor, $invoiceId, 'cancelled') : ($paymentId > 0
            ? $service->update($actor, $invoiceId, $paymentId, ['amount'=>$amount,'version'=>1,'reason'=>'Concurrent test correction'])
            : $service->create($actor, $invoiceId, ['amount'=>$amount,'payment_date'=>date('Y-m-d'),'payment_method'=>'cash']));
        echo json_encode(['status'=>201,'id'=>$result->id]);
    } elseif ($op[0] === 'http') {
        [, $method, $path, $data] = $op;
        $request = Illuminate\Http\Request::create($path,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($data));
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        echo json_encode(['status'=>$response->getStatusCode()]);
    } else {
        [$kind, $entityId] = $op;
        $type = $kind === 'quote' ? 'quote' : 'invoice';
        $data = ['client_id'=>$entityId,$type.'_date'=>today()->toDateString(),'status'=>'draft',
            'items'=>[['description'=>'Concurrent line','quantity'=>'1','unit_price'=>'10.00','tax_rate'=>'0']]];
        $path = '/api/'.$type.'s'; $method = 'POST';
        if ($kind === 'convert' || $kind === 'edit') {
            $path = '/api/quotes/'.$entityId.($kind === 'convert' ? '/convert' : '');
            $method = $kind === 'convert' ? 'POST' : 'PATCH';
            $data = $kind === 'convert' ? [] : ['notes'=>'Concurrent forbidden edit'];
        }
        $request = Illuminate\Http\Request::create($path,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($data));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request); $data = json_decode($response->getContent(), true);
        echo json_encode(['status'=>$response->getStatusCode(),'id'=>$data['id']??null,'number'=>$data['invoice_number']??$data['quote_number']??null]);
    }
} catch (Illuminate\Validation\ValidationException $exception) { echo json_encode(['status'=>422]); }
catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) { echo json_encode(['status'=>$exception->getStatusCode()]); }
catch (Throwable $exception) { fwrite(STDERR,get_class($exception)); exit(4); }
PHP;
    }
}
