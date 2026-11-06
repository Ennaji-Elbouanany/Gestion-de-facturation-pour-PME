<?php

use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || ! str_ends_with($config['database'], '_test') || ! in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, 'A local isolated MySQL test database is required.');
    exit(2);
}
[$script, $actorId, $invoiceId, $paymentId, $amount, $ready, $gate] = $argv;
$actor = User::findOrFail($actorId);
file_put_contents($ready, 'ready');
$deadline = microtime(true) + 15;
while (! is_file($gate)) {
    if (microtime(true) > $deadline) {
        exit(3);
    } usleep(10000);
}
try {
    $service = app(PaymentService::class);
    file_put_contents($ready.'.attempt', 'attempt');
    $payment = (int) $paymentId === -1 ? app(InvoiceService::class)->transition($actor, (int) $invoiceId, 'cancelled') : ((int) $paymentId > 0
        ? $service->update($actor, (int) $invoiceId, (int) $paymentId, ['amount' => $amount, 'version' => 1, 'reason' => 'Concurrent test correction'])
        : $service->create($actor, (int) $invoiceId, ['amount' => $amount, 'payment_date' => date('Y-m-d'), 'payment_method' => 'cash']));
    echo json_encode(['status' => 201, 'id' => $payment->id]);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 422]);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception));
    exit(4);
}
