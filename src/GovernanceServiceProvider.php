<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use Illuminate\Support\ServiceProvider;

final class GovernanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
