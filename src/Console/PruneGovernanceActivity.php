<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Console;

use Illuminate\Console\Command;
use Ocdejong\SharedToolGovernance\Activity\PruneActivity;

final class PruneGovernanceActivity extends Command
{
    protected $signature = 'shared-tool-governance:prune';

    protected $description = 'Clear expired governance inputs and private card content';

    public function handle(PruneActivity $activity): int
    {
        $count = $activity->prune();
        $this->info("Pruned governance data for {$count} invocation(s).");

        return self::SUCCESS;
    }
}
