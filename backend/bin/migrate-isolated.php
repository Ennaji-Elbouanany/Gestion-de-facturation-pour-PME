<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// This entry point never accepts production or remote database connections.
if (getenv('APP_ENV') !== 'testing' || ($argv[1] ?? '') !== '--apply-isolated') {
    fwrite(STDERR, "Refused: explicit testing environment and --apply-isolated required.\n");
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app['db']->connection();
$safe = ($connection->getDriverName() === 'sqlite' && ($connection->getDatabaseName() === ':memory:'
    || $connection->getDatabaseName() === '/tmp/billing_test.sqlite'))
    || ($connection->getDriverName() === 'mysql'
        && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
        && str_ends_with($connection->getDatabaseName(), '_test'));
if (! $app->environment('testing') || ! $safe) {
    fwrite(STDERR, "Refused: resolved database must be isolated and local.\n");
    exit(1);
}
exit(Artisan::call('migrate', ['--force' => true]));
