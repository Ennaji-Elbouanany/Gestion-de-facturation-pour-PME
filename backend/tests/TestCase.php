<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        $safeSqlite = $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $safeMySql = $connection->getDriverName() === 'mysql'
            && str_ends_with($connection->getDatabaseName(), '_test')
            && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true);
        if (! $app->environment('testing') || (! $safeSqlite && ! $safeMySql)) {
            throw new RuntimeException('Les tests exigent SQLite en mémoire ou une base MySQL locale *_test. Aucune migration de test autorisée sur cette connexion.');
        }

        return $app;
    }
}
