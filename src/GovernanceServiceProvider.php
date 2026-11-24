<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use Illuminate\Support\ServiceProvider;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;

final class GovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(RuleEvaluator::class, ResourceRules::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
