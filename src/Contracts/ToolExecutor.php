<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;

/** Bind the implementation in the host container under the registered tool's executorId. */
interface ToolExecutor
{
    /**
     * Forward the normalized arguments to the tool. The reason is audit data.
     *
     * @param  list<ResourceReference>  $approvedTargets
     */
    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult;
}
