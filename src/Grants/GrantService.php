<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use SensitiveParameter;

final readonly class GrantService
{
    private const int LIFETIME_MINUTES = 5;

    public function __construct(private CallPolicy $policy, private TrustedInvocationContext $context) {}

    /** @internal Call in the same transaction that grants the invocation. */
    public static function issue(GovernanceInvocation $invocation): string
    {
        $token = bin2hex(random_bytes(32));
        GovernanceGrant::query()->forceCreate([
            'invocation_row_id' => $invocation->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return $token;
    }

    public function redeem(#[SensitiveParameter] string $grant, Invocation $actualCall): Redemption
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('Redemption must commit before the executor can act.');
        }

        try {
            $frozen = $this->policy->freeze($actualCall);
            $fingerprint = $frozen->fingerprint();
        } catch (InvalidArgumentException) {
            throw new RedemptionRefused;
        }

        $invocations = InvocationStore::query($frozen->call->context->scope, $actualCall->id);
        $invocations->getQuery()->useWritePdo();
        $row = $invocations->first();
        if ($row === null || $row->getAttribute('status') !== 'granted' || $row->getAttribute('fingerprint') !== $fingerprint) {
            throw new RedemptionRefused;
        }

        $tokenHash = hash('sha256', $grant);

        return DB::transaction(function () use ($row, $frozen, $tokenHash): Redemption {
            $query = GovernanceGrant::query()
                ->where('invocation_row_id', $row->getKey())
                ->where('token_hash', $tokenHash)
                ->where('revoked_at', null)
                ->where('redeemed_at', null);
            $query->getQuery()->lockForUpdate();
            $locked = $query->first();
            if ($locked === null || $locked->getAttribute('expires_at') <= now()) {
                throw new RedemptionRefused;
            }

            $decisions = $this->policy->evaluate($frozen);
            if ($decisions === null || array_diff(array_column($decisions, 'verdict'), [Verdict::ALLOW->value]) !== []) {
                throw new RedemptionRefused;
            }

            $inputs = $row->getAttribute('frozen_inputs');
            $approved = $inputs['decisions'] ?? [];
            if (
                array_column($approved, 'owner_type') !== array_column($decisions, 'owner_type')
                || array_column($approved, 'owner_id') !== array_column($decisions, 'owner_id')
            ) {
                throw new RedemptionRefused;
            }

            $argumentsJson = $inputs['arguments_json'] ?? null;
            if (! is_string($argumentsJson)) {
                throw new RedemptionRefused;
            }

            $arguments = json_decode($argumentsJson, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($arguments)) {
                throw new RedemptionRefused;
            }

            $now = now();
            if ($query->whereKey($locked->getKey())->where('expires_at', '>', $now)->update(['redeemed_at' => $now]) !== 1) {
                throw new RedemptionRefused;
            }

            InvocationStore::audit($row, 'redeemed', ['rule_revisions' => array_column($decisions, 'rule_revision')]);

            $call = $frozen->call;
            $approvedCall = new Invocation($call->id, $call->toolName, $call->toolVersion, $arguments, $call->reason, $call->context, $call->executorId);

            return new Redemption($approvedCall, $frozen->targets);
        }, 3);
    }

    public function recordOutcome(string $invocationId, ExecutionStatus $outcome, ?string $providerReference = null): void
    {
        $context = $this->context->current();
        DB::transaction(function () use ($context, $invocationId, $outcome, $providerReference): void {
            $query = InvocationStore::query($context->scope, $invocationId)
                ->where('requester_id', $context->requesterId)
                ->where('runtime_id', $context->runtimeId);
            $query->getQuery()->lockForUpdate();
            $row = $query->first();
            if ($row === null || ! GovernanceGrant::query()->where('invocation_row_id', $row->getKey())->whereNotNull('redeemed_at')->exists()) {
                throw new DomainException('No redeemed grant for this invocation.');
            }

            $previous = $row->getAttribute('outcome');
            if ($previous === $outcome && $row->getAttribute('provider_reference') === $providerReference) {
                return;
            }

            if ($previous !== null && ($previous !== ExecutionStatus::UNKNOWN || $outcome === ExecutionStatus::UNKNOWN)) {
                throw new DomainException('An outcome has already been recorded for this invocation.');
            }

            $row->forceFill([
                'outcome' => $outcome,
                'provider_reference' => $providerReference,
                'outcome_recorded_at' => now(),
            ])->save();
            InvocationStore::audit($row, 'outcome_recorded', ['outcome' => $outcome->value]);
        }, 3);
    }
}
