<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Ocdejong\SharedToolGovernance\Console\PruneGovernanceActivity;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
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
        $this->commands([PruneGovernanceActivity::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->call(static fn () => app(ApprovalWork::class)->recover())
                ->name('shared-tool-governance:recover-approvals')
                ->everyMinute();
            $schedule->command(PruneGovernanceActivity::class)->daily();
        });
    }
}
