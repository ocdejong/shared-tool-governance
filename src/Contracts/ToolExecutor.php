<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;

interface ToolExecutor
{
    /**
     * Forward the normalized arguments to the tool. The reason is audit data.
     *
     * @param  list<ResourceReference>  $approvedTargets
     */
    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult;
}
