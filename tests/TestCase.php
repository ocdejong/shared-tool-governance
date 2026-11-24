<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Ocdejong\SharedToolGovernance\GovernanceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [GovernanceServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());
    }

    /** @return array<string, mixed> */
    private static function databaseConnection(): array
    {
        $database = getenv('DB_DATABASE');

        if ($database === false) {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true];
        }

        if ($database !== 'shared_tool_governance') {
            throw new RuntimeException('PostgreSQL tests run only against the dedicated shared_tool_governance database.');
        }

        return [
            'driver' => 'pgsql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'database' => $database,
            'username' => getenv('DB_USERNAME') ?: 'sail',
            'password' => getenv('DB_PASSWORD') ?: '',
        ];
    }
}
