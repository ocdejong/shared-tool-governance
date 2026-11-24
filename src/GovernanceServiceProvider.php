<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Ocdejong\SharedToolGovernance\Console\PruneGovernanceActivity;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final class GovernanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(RuleEvaluator::class, ResourceRules::class);
        $this->app->singletonIf(ToolRegistry::class);
        $this->app->bindIf(ToolDiscovery::class, static fn (Container $app) => new ToolDiscovery(
            $app->make(TrustedInvocationContext::class),
            $app->make(ResourceAuthority::class),
            $app->make(RuleEvaluator::class),
            $app->make(ToolRegistry::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->commands([PruneGovernanceActivity::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(static fn () => app(ApprovalWork::class)->recover())
                ->name('shared-tool-governance:recover-approvals')
                ->everyMinute();
            $schedule->command(PruneGovernanceActivity::class)->daily();
        });
    }
}
