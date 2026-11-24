<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Activity;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Authorization\ScopeAccess;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Grants\GrantClock;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

/**
 * @phpstan-import-type Target from ReceiptTargets
 *
 * @phpstan-type Approval array{approver_id: string, decision: string, decided_at: string}
 * @phpstan-type Receipt array{status: string, outcome?: string, requester_id: string, approvals: list<Approval>, reason?: string, invocation_id?: string, action?: string, requested_at?: string, targets?: list<array{id: string, type: string, account_alias: string|null, verdict: string, rule_key: string|null, rule_revision: int|null}>}
 */
final readonly class Activity
{
    private const int RECENT_LIMIT = 50;

    private ScopeAccess $access;

    public function __construct(private ResourceAuthority $authority, private TrustedInvocationContext $context)
    {
        $this->access = new ScopeAccess($authority);
    }

    public function revoke(string $invocationId): bool
    {
        $viewer = $this->context->current();
        if (! $this->access->allows($viewer->scope, $viewer->scope, $viewer->requesterId)) {
            return false;
        }

        return DB::transaction(function () use ($viewer, $invocationId): bool {
            $query = InvocationStore::query($viewer->scope, $invocationId);
            $query->getQuery()->lockForUpdate();
            $row = $query->first();

            if ($row === null || (! $this->isRequester($row, $viewer) && $this->visibleTargets($row, $viewer) === [])) {
                return false;
            }

            if (! self::claimRevocation($row)) {
                return false;
            }

            ApprovalStore::move($row, 'revoked');

            return true;
        }, 3);
    }

    /** @return list<Receipt> */
    public function recentUse(?string $invocationId = null): array
    {
        $viewer = $this->context->current();
        if (! $this->access->allowsContext($viewer->scope, $viewer)) {
            return [];
        }

        $query = GovernanceInvocation::query()
            ->where('host_scope', $viewer->scope->hostScope)
            ->where('household_id', $viewer->scope->workspaceId);
        if ($invocationId !== null) {
            $query->where('invocation_id', $invocationId);
        }
        $query->getQuery()->useWritePdo();

        $now = now();

        $receipts = [];
        foreach ($query->lazyByIdDesc() as $row) {
            $receipt = $this->receipt($row, $viewer, $now);
            if ($receipt === null) {
                continue;
            }

            $receipts[] = $receipt;
            if (count($receipts) === self::RECENT_LIMIT) {
                break;
            }
        }

        return $receipts;
    }

    private static function claimRevocation(GovernanceInvocation $row): bool
    {
        $status = $row->getAttribute('status');
        if (in_array($status, ['pending_approval', 'approved'], true)) {
            return ApprovalStore::expiresAt($row) > now();
        }

        if ($status !== 'granted') {
            return false;
        }

        $query = ActivityState::grant($row)->where('redeemed_at', null)->where('revoked_at', null);
        $query->getQuery()->lockForUpdate();
        $grant = $query->first();
        if ($grant === null) {
            return false;
        }

        $now = GrantClock::sql();

        return $query->whereRaw(DB::raw('expires_at > '.$now))->update(['revoked_at' => DB::raw($now)]) === 1;
    }

    private function isRequester(GovernanceInvocation $row, InvocationContext $viewer): bool
    {
        return $row->getAttribute('requester_id') === $viewer->requesterId;
    }

    /** @return Receipt|null */
    private function receipt(GovernanceInvocation $row, InvocationContext $viewer, CarbonInterface $now): ?array
    {
        $isRequester = $this->isRequester($row, $viewer);
        $targets = $isRequester ? [] : $this->visibleTargets($row, $viewer);
        if (! $isRequester && $targets === []) {
            return null;
        }

        $state = ActivityState::read($row, $now);
        $receipt = ['status' => $state->status];
        if ($state->outcome !== null) {
            $receipt['outcome'] = $state->outcome->value;
        }

        $receipt['requester_id'] = $row->getAttribute('requester_id');
        $receipt['approvals'] = self::decidedApprovals($row, $isRequester ? null : $targets);

        if ($isRequester) {
            $inputs = $row->getAttribute('frozen_inputs');
            $reason = $inputs === null ? null : ($inputs['reason'] ?? $row->getRawOriginal('reason'));

            return is_string($reason) ? $receipt + ['reason' => $reason] : $receipt;
        }

        return $receipt + [
            'invocation_id' => $row->getAttribute('invocation_id'),
            'action' => $row->getAttribute('action_key'),
            'requested_at' => $row->getAttribute('created_at')->toIso8601String(),
            'targets' => array_map(static fn (array $target): array => [
                'id' => $target['id'],
                'type' => $target['type'],
                'account_alias' => $target['accountAlias'],
                'verdict' => $target['verdict'],
                'rule_key' => $target['rule_key'],
                'rule_revision' => $target['rule_revision'],
            ], $targets),
        ];
    }

    /**
     * @param  list<Target>|null  $targets
     * @return list<Approval>
     */
    private static function decidedApprovals(GovernanceInvocation $row, ?array $targets): array
    {
        $owners = [];
        foreach ($targets ?? [] as $target) {
            if ($target['verdict'] === Verdict::DELEGATE->value) {
                $owners[$target['owner_type']][$target['owner_id']] = true;
            }
        }

        if ($targets !== null && $owners === []) {
            return [];
        }

        $query = ApprovalStore::approvals($row);
        $query->getQuery()->whereNotNull('decision')->whereNotNull('decided_at')
            ->orderBy('decided_at')->orderBy('id')->useWritePdo();

        $decisions = [];
        foreach ($query->get(['owner_type', 'owner_id', 'approver_id', 'decision', 'decided_at']) as $approval) {
            if ($targets !== null && ! isset($owners[$approval->getAttribute('owner_type')->value][$approval->getAttribute('owner_id')])) {
                continue;
            }

            $decisions[] = [
                'approver_id' => $approval->getAttribute('approver_id'),
                'decision' => $approval->getAttribute('decision'),
                'decided_at' => $approval->getAttribute('decided_at')->toIso8601String(),
            ];
        }

        return $decisions;
    }

    /** @return list<Target> */
    private function visibleTargets(GovernanceInvocation $row, InvocationContext $viewer): array
    {
        return array_values(array_filter(
            ReceiptTargets::read($row),
            fn (array $target): bool => $this->canManage($target, $viewer),
        ));
    }

    /** @param Target $target */
    private function canManage(array $target, InvocationContext $viewer): bool
    {
        $resource = new ResourceReference($target['type'], $target['id'], $target['revision'], $target['accountAlias']);
        $owner = $this->authority->ownerOf($viewer->scope, $resource);
        if ($owner === null || $owner->type->value !== $target['owner_type'] || $owner->id !== $target['owner_id']
            || ! $this->access->includesOwner($viewer->scope, $owner)) {
            return false;
        }

        if ($owner->type === OwnerType::PERSON && $owner->id === $viewer->requesterId) {
            return true;
        }

        return $this->authority->canManageRules($viewer->scope, $viewer->requesterId, $resource, $owner);
    }
}
