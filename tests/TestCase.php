<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Ocdejong\SharedToolGovernance\GovernanceServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

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
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);

        if (getenv('GOVERNANCE_TEST_POSTGRES') === '1') {
            $app['config']->set('database.connections.testing', [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 5432,
                'database' => 'shared_tool_governance',
                'username' => 'sail',
                'password' => getenv('DB_PASSWORD'),
            ]);
        }
    }
}
