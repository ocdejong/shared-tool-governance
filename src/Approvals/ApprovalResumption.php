<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use DomainException;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Authorization\FrozenCall;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Grants\RedemptionRefused;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use SensitiveParameter;
use Throwable;

/** @internal */
final readonly class ApprovalResumption
{
    public function __construct(
        private ApprovalPolicy $approvals,
        private ToolExecutor $executor,
    ) {}

    public function resume(int $rowId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('Redemption must commit before the executor can act.');
        }

        $grant = DB::transaction(fn () => $this->issue($rowId), 3);
        if ($grant === null) {
            return;
        }

        $frozen = DB::transaction(fn () => $this->redeem($rowId, $grant), 3);
        if ($frozen === null) {
            return;
        }

        try {
            $result = $this->executor->execute($frozen->call, $frozen->targets);
        } catch (Throwable) {
            // Once the executor starts, an exception cannot establish that it had no effect.
            return;
        }

        DB::transaction(static function () use ($rowId, $result): void {
            $row = ApprovalStore::locked($rowId) ?? throw new DomainException('Redeemed invocation is missing.');
            GrantService::recordLockedOutcome($row, $result->status, $result->providerReference);
        }, 3);
    }

    private function issue(int $rowId): ?string
    {
        $row = ApprovalStore::locked($rowId);
        if ($row === null) {
            return null;
        }

        $status = $row->getAttribute('status');
        if ($status === 'granted') {
            $this->closeExpiredGrant($row);

            return null;
        }

        if (! in_array($status, ['approved', 'pending_approval'], true)) {
            return null;
        }

        if (ApprovalStore::expiresAt($row) <= now()) {
            ApprovalStore::move($row, 'expired');

            return null;
        }

        if ($status !== 'approved') {
            return null;
        }

        if ($this->approvals->recheck($row) === null) {
            ApprovalStore::move($row, 'revoked');

            return null;
        }

        if (ApprovalStore::expiresAt($row) <= now()) {
            ApprovalStore::move($row, 'expired');

            return null;
        }

        $token = GrantService::issue($row);
        ApprovalStore::move($row, 'granted');

        return $token;
    }

    private function redeem(int $rowId, #[SensitiveParameter] string $grant): ?FrozenCall
    {
        $row = ApprovalStore::locked($rowId);
        if ($row === null || $row->getAttribute('status') !== 'granted') {
            return null;
        }

        try {
            $locked = GrantService::lockForRedemption($row, $grant);
            $current = $this->approvals->recheck($row);
            if ($current === null || ApprovalStore::expiresAt($row) <= now()) {
                throw new RedemptionRefused;
            }

            [$frozen, $decisions] = $current;
            GrantService::consume($row, $locked, array_column($decisions, 'rule_revision'));
        } catch (RedemptionRefused) {
            $this->markUnused($row);

            return null;
        }

        $row->forceFill(['frozen_inputs' => ApprovalStore::auditInputs($row)])->save();
        // A restart after consumption cannot tell whether the downstream tool acted.
        GrantService::recordLockedOutcome($row, ExecutionStatus::UNKNOWN);

        return $frozen;
    }

    private function closeExpiredGrant(GovernanceInvocation $row): void
    {
        if (! ApprovalStore::approvals($row)->exists()) {
            return;
        }

        $grant = GovernanceGrant::query()->where('invocation_row_id', $row->getKey())->where('redeemed_at', null)->first();
        if ($grant !== null && ($grant->getAttribute('revoked_at') !== null || $grant->getAttribute('expires_at') <= now())) {
            $this->markUnused($row);
        }
    }

    private function markUnused(GovernanceInvocation $row): void
    {
        $unused = GovernanceGrant::query()->where('invocation_row_id', $row->getKey())->where('redeemed_at', null);
        if ($unused->update(['revoked_at' => now()]) === 1) {
            ApprovalStore::move($row, 'approved_unused');
        }
    }
}
