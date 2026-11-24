<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Execution;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Throwable;

/** @internal */
final readonly class ExecuteTool
{
    public function __construct(private Container $container) {}

    /** @param list<ResourceReference> $targets */
    public function run(int $rowId, Invocation $call, array $targets): ExecutionResult
    {
        $executor = $this->container->make($call->executorId);
        if (! $executor instanceof ToolExecutor) {
            throw new InvalidArgumentException('The registered executor must implement ToolExecutor.');
        }

        try {
            $result = $executor->execute($call, $targets);
        } catch (Throwable) {
            // The tool might have acted before throwing.
            return new ExecutionResult(ExecutionStatus::UNKNOWN);
        }

        DB::transaction(static function () use ($rowId, $result): void {
            $query = GovernanceInvocation::query()->whereKey($rowId);
            $query->getQuery()->lockForUpdate();
            GrantService::recordLockedOutcome($query->firstOrFail(), $result->status, $result->providerReference);
        }, 3);

        return $result;
    }
}
