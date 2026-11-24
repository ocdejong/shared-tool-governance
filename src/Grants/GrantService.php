<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Execution\ExecuteTool;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use SensitiveParameter;

final readonly class GrantService
{
    private const int LIFETIME_MINUTES = 5;

    public function __construct(
        private CallPolicy $policy,
        private TrustedInvocationContext $context,
        private ExecuteTool $executor,
    ) {}

    /** @internal Call in the same transaction that grants the invocation. */
    public static function issue(GovernanceInvocation $invocation): string
    {
        $token = bin2hex(random_bytes(32));
        $now = DB::raw(GrantClock::sql());
        GovernanceGrant::query()->insert([
            'invocation_row_id' => $invocation->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => DB::raw(GrantClock::sql(self::LIFETIME_MINUTES)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $token;
    }

    /** Consume the matching grant before running the registered executor. */
    public function redeem(#[SensitiveParameter] string $grant, Invocation $actualCall): ExecutionResult
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

        $executor = $this->executor->resolve($frozen->call->executorId);
        $redemption = DB::transaction(function () use ($frozen, $fingerprint, $grant): Redemption {
            $query = InvocationStore::query($frozen->call->context->scope, $frozen->call->id);
            $query->getQuery()->lockForUpdate();
            $row = $query->first();
            if ($row === null || $row->getAttribute('status') !== 'granted' || $row->getAttribute('fingerprint') !== $fingerprint) {
                throw new RedemptionRefused;
            }

            $locked = self::lockForRedemption($row, $grant);
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

            self::consume($row, $locked, array_column($decisions, 'rule_revision'));
            // A crash after redemption leaves the downstream outcome unknown.
            self::recordLockedOutcome($row, ExecutionStatus::UNKNOWN);

            $call = $frozen->call;
            $approvedCall = new Invocation($call->id, $call->toolName, $call->toolVersion, $arguments, $call->reason, $call->context, $call->executorId);

            return new Redemption((int) $row->getKey(), $approvedCall, $frozen->targets);
        }, 3);

        return $this->executor->run($executor, $redemption->rowId, $redemption->call, $redemption->targets);
    }

    /** @internal Lock before evaluating the current authority for redemption. */
    public static function lockForRedemption(GovernanceInvocation $row, #[SensitiveParameter] string $grant): GovernanceGrant
    {
        $query = GovernanceGrant::query()
            ->where('invocation_row_id', $row->getKey())
            ->where('token_hash', hash('sha256', $grant))
            ->where('revoked_at', null)
            ->where('redeemed_at', null);
        $query->getQuery()->whereRaw(DB::raw('expires_at > '.GrantClock::sql()))->lockForUpdate();
        $locked = $query->first();
        if ($locked === null) {
            throw new RedemptionRefused;
        }

        return $locked;
    }

    /**
     * @internal Consume after checking authority while holding the grant lock.
     *
     * @param  list<int|null>  $ruleRevisions
     */
    public static function consume(GovernanceInvocation $row, GovernanceGrant $locked, array $ruleRevisions): void
    {
        $now = GrantClock::sql();
        $updated = GovernanceGrant::query()->whereKey($locked->getKey())
            ->where('invocation_row_id', $row->getKey())
            ->where('revoked_at', null)
            ->where('redeemed_at', null)
            ->whereRaw(DB::raw('expires_at > '.$now))
            ->update(['redeemed_at' => DB::raw($now), 'updated_at' => DB::raw($now)]);
        if ($updated !== 1) {
            throw new RedemptionRefused;
        }

        InvocationStore::audit($row, 'redeemed', ['rule_revisions' => $ruleRevisions]);
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

            self::recordLockedOutcome($row, $outcome, $providerReference);
        }, 3);
    }

    /** @internal Record while holding the invocation lock and after successful redemption. */
    public static function recordLockedOutcome(GovernanceInvocation $row, ExecutionStatus $outcome, ?string $providerReference = null): void
    {
        $previous = $row->getAttribute('outcome');
        $previousReference = $row->getAttribute('provider_reference');
        if ($previous === $outcome && $previousReference === $providerReference) {
            return;
        }

        if ($previous !== null && ($previous !== ExecutionStatus::UNKNOWN
            || ($outcome === ExecutionStatus::UNKNOWN && $previousReference !== null))) {
            throw new DomainException('An outcome has already been recorded for this invocation.');
        }

        $row->forceFill([
            'outcome' => $outcome,
            'provider_reference' => $providerReference,
            'outcome_recorded_at' => now(),
        ])->save();
        InvocationStore::audit($row, 'outcome_recorded', ['outcome' => $outcome->value]);
    }
}
