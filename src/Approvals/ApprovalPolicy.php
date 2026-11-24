<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\FrozenCall;
use Ocdejong\SharedToolGovernance\Contracts\ResumptionContext;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

/**
 * @phpstan-import-type Decision from CallPolicy
 */
final readonly class ApprovalPolicy
{
    public function __construct(private CallPolicy $policy, private ResumptionContext $host) {}

    /** @return array{FrozenCall, list<Decision>}|null */
    public function recheck(GovernanceInvocation $row): ?array
    {
        $scope = ApprovalStore::scope($row);
        $inputs = $row->getAttribute('frozen_inputs');
        $reason = $inputs['reason'] ?? $row->getRawOriginal('reason');
        if (! is_string($reason) || ! isset($inputs['arguments_json'], $inputs['approval_requirements'])) {
            return null;
        }

        $live = $this->host->forInvocation($scope, $row->getAttribute('invocation_id'));
        if ($live === null) {
            return null;
        }

        $call = new Invocation(
            $row->getAttribute('invocation_id'),
            $row->getAttribute('tool_name'),
            $row->getAttribute('tool_version'),
            json_decode($inputs['arguments_json'], true, flags: JSON_THROW_ON_ERROR),
            $reason,
            new InvocationContext($scope, $row->getAttribute('requester_id'), $row->getAttribute('runtime_id'), $row->getAttribute('audience')),
            $row->getAttribute('executor_id'),
        );
        try {
            $frozen = $this->policy->restore($call, $live);
            if ($frozen->fingerprint() !== $row->getAttribute('fingerprint')) {
                return null;
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        $decisions = $this->policy->evaluate($frozen);
        if ($decisions === null || $decisions !== $inputs['decisions']
            || $this->policy->approvalRequirements($frozen, $decisions) !== $inputs['approval_requirements']) {
            return null;
        }

        return [$frozen, $decisions];
    }
}
