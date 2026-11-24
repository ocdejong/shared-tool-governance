<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface CompletionHandler
{
    /**
     * Recheck the current audience before delivering a delegated result.
     * Use the scope and invocation ID to make delivery idempotent.
     */
    public function completed(Scope $scope, string $invocationId, ExecutionResult $result): void;
}
