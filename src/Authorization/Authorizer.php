<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;

final readonly class Authorizer
{
    public function __construct(private CallPolicy $policy) {}

    public function authorize(Invocation $invocation): AuthorizationResult
    {
        $frozen = $this->policy->freeze($invocation);
        $fingerprint = $frozen->fingerprint();
        $query = InvocationStore::query($frozen->call->context->scope, $invocation->id);
        $query->getQuery()->useWritePdo();

        $existing = $query->first();
        if ($existing !== null) {
            return self::existingResult($existing, $invocation->id, $fingerprint);
        }

        try {
            return DB::transaction(fn () => $this->decide($frozen, $fingerprint), 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $query->first() ?? throw $exception;

            return self::existingResult($existing, $invocation->id, $fingerprint);
        }
    }

    private function decide(FrozenCall $frozen, string $fingerprint): AuthorizationResult
    {
        $decisions = $this->policy->evaluate($frozen);
        $verdicts = array_column($decisions ?? [], 'verdict');
        $status = match (true) {
            $decisions === null, in_array(Verdict::DENY->value, $verdicts, true) => 'denied',
            in_array(Verdict::DELEGATE->value, $verdicts, true) => 'pending_approval',
            default => 'granted',
        };

        $requirements = $status === 'pending_approval' ? $this->policy->approvalRequirements($frozen, $decisions) : null;
        $call = $frozen->call;
        $context = $call->context;
        $row = GovernanceInvocation::query()->forceCreate([
            'host_scope' => $context->scope->hostScope,
            'household_id' => $context->scope->householdId,
            'invocation_id' => $call->id,
            'tool_name' => $call->toolName,
            'tool_version' => $call->toolVersion,
            'action_key' => $frozen->tool->actionKey,
            'requester_id' => $context->requesterId,
            'runtime_id' => $context->runtimeId,
            'executor_id' => $call->executorId,
            'reason' => $call->reason,
            'audience' => $context->audience,
            'frozen_inputs' => $frozen->inputs() + ['decisions' => $decisions, 'approval_requirements' => $requirements],
            'fingerprint' => $fingerprint,
            'status' => $status,
        ]);
        $token = $status === 'granted' ? GrantService::issue($row) : null;
        InvocationStore::audit($row, $status);
        if ($requirements !== null) {
            ApprovalStore::stage($row, $frozen, $requirements);
            DB::afterCommit(static fn () => ApprovalWork::deliverFor((int) $row->getKey()));
        }

        return new AuthorizationResult($status, $call->id, $token);
    }

    private static function existingResult(GovernanceInvocation $row, string $id, string $fingerprint): AuthorizationResult
    {
        if ($row->getAttribute('fingerprint') !== $fingerprint) {
            throw new InvocationConflict('Invocation ID is already bound to a different call.');
        }

        return new AuthorizationResult($row->getAttribute('status'), $id);
    }
}
