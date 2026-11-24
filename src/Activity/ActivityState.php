<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Activity;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

/** @internal */
final readonly class ActivityState
{
    public function __construct(
        public string $status,
        public ?ExecutionStatus $outcome,
        public ?CarbonInterface $terminalAt,
    ) {}

    /** @return Builder<GovernanceGrant> */
    public static function grant(GovernanceInvocation $row): Builder
    {
        $query = GovernanceGrant::query()->where('invocation_row_id', $row->getKey());
        $query->getQuery()->useWritePdo();

        return $query;
    }

    public static function read(GovernanceInvocation $row, CarbonInterface $now): self
    {
        $status = $row->getAttribute('status');
        if (in_array($status, ['pending_approval', 'approved'], true)) {
            $expires = ApprovalStore::expiresAt($row);

            return $expires <= $now ? new self('expired', null, $expires) : new self($status, null, null);
        }

        if ($status !== 'granted') {
            $terminalAt = in_array($status, ['denied', 'declined', 'expired', 'revoked', 'approved_unused'], true)
                ? $row->getAttribute('updated_at') : null;

            return new self($status, null, $terminalAt);
        }

        $grant = self::grant($row)->first();
        if ($grant?->getAttribute('redeemed_at') !== null) {
            $outcome = $row->getAttribute('outcome') ?? ExecutionStatus::UNKNOWN;

            return new self($status, $outcome, $outcome === ExecutionStatus::UNKNOWN ? null : $row->getAttribute('outcome_recorded_at'));
        }

        $revoked = $grant?->getAttribute('revoked_at');
        if ($revoked !== null) {
            return new self('revoked', null, $revoked);
        }

        $expires = $grant?->getAttribute('expires_at');

        return $expires !== null && $expires <= $now
            ? new self('approved_unused', null, $expires)
            : new self($status, null, null);
    }
}
