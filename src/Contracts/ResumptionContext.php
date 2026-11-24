<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface ResumptionContext
{
    /** Resolve current trusted context from the host's persisted request. Null means it is no longer valid. */
    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext;
}
