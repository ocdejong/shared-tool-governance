<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use DomainException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Authorization\FrozenCall;
use Ocdejong\SharedToolGovernance\Contracts\CompletionHandler;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Execution\ExecuteTool;
use Ocdejong\SharedToolGovernance\Grants\GrantClock;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Grants\RedemptionRefused;
use Ocdejong\SharedToolGovernance\Jobs\DeliverCompletion;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use SensitiveParameter;

/** @internal */
final readonly class ApprovalResumption
{
    public function __construct(
        private ApprovalPolicy $approvals,
        private ExecuteTool $executor,
    ) {}

    public function resume(int $rowId): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('Redemption must commit before the executor can act.');
        }

        $issued = DB::transaction(fn () => $this->issue($rowId), 3);
        if ($issued === null) {
            return;
        }

        [$grant, $executor] = $issued;
        $frozen = DB::transaction(fn () => $this->redeem($rowId, $grant), 3);
        if ($frozen === null) {
            return;
        }

        $call = $frozen->call;
        $result = $this->executor->run($executor, $rowId, $call, $frozen->targets);
        if (App::bound(CompletionHandler::class)) {
            Bus::dispatch(new DeliverCompletion($call->context->scope, $call->id, $result));
        }
    }

    /** @return array{string, ToolExecutor}|null */
    private function issue(int $rowId): ?array
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

        $current = $this->approvals->recheck($row);
        if ($current === null) {
            ApprovalStore::move($row, 'revoked');

            return null;
        }

        // A failed binding must leave the approved work available for a retry.
        [$frozen] = $current;
        $executor = $this->executor->resolve($frozen->call->executorId);

        if (ApprovalStore::expiresAt($row) <= now()) {
            ApprovalStore::move($row, 'expired');

            return null;
        }

        $token = GrantService::issue($row);
        ApprovalStore::move($row, 'granted');

        return [$token, $executor];
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

        $expiredOrRevoked = GovernanceGrant::query()
            ->where('invocation_row_id', $row->getKey())
            ->whereNull('redeemed_at')
            ->where(fn ($query) => $query->whereNotNull('revoked_at')->orWhereRaw('expires_at <= '.GrantClock::sql()))
            ->exists();
        if ($expiredOrRevoked) {
            $this->markUnused($row);
        }
    }

    private function markUnused(GovernanceInvocation $row): void
    {
        $unused = GovernanceGrant::query()->where('invocation_row_id', $row->getKey())->where('redeemed_at', null);
        if ($unused->update(['revoked_at' => DB::raw(GrantClock::sql())]) === 1) {
            ApprovalStore::move($row, 'approved_unused');
        }
    }
}
