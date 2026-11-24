<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Models\GovernanceAuditEvent;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

final class InvocationStore
{
    /** @return Builder<GovernanceInvocation> */
    public static function query(Scope $scope, string $id): Builder
    {
        return GovernanceInvocation::query()
            ->where('host_scope', $scope->hostScope)
            ->where('household_id', $scope->workspaceId)
            ->where('invocation_id', $id);
    }

    /** @param array<string, mixed> $details */
    public static function audit(GovernanceInvocation $invocation, string $event, array $details = []): void
    {
        GovernanceAuditEvent::query()->forceCreate([
            'host_scope' => $invocation->getAttribute('host_scope'),
            'household_id' => $invocation->getAttribute('household_id'),
            'invocation_row_id' => $invocation->getKey(),
            'event' => $event,
            'details' => $details,
        ]);
    }
}
