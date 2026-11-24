<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Contracts\ApproverPolicy;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

/**
 * @phpstan-type Decision array{owner_type: string, owner_id: string, verdict: string, rule_key: string|null, rule_revision: int|null}
 * @phpstan-type Eligibility array{approver_id: string}|array{mode: string, eligible_people: list<string>}
 * @phpstan-type Requirement array{owner_type: string, owner_id: string, approver_id: string}|array{owner_type: string, owner_id: string, mode: string, eligible_people: list<string>}
 */
final readonly class CallPolicy
{
    private ScopeAccess $access;

    public function __construct(
        private ToolRegistry $tools,
        private TrustedInvocationContext $context,
        private ResourceAuthority $authority,
        private RuleEvaluator $rules,
        private ?ApproverPolicy $approvers = null,
    ) {
        $this->access = new ScopeAccess($authority);
    }

    public function freeze(Invocation $proposal): FrozenCall
    {
        if (preg_match('/[^\s\p{C}\p{Default_Ignorable_Code_Point}]/u', $proposal->reason) !== 1) {
            throw new InvalidArgumentException('A non-blank reason is required.');
        }

        return $this->resolve($proposal, $this->context->current(), normalize: true);
    }

    /** @internal Restore stored, already-normalized arguments against current host context. */
    public function restore(Invocation $call, InvocationContext $trusted): FrozenCall
    {
        return $this->resolve($call, $trusted, normalize: false);
    }

    private function resolve(Invocation $proposal, InvocationContext $trusted, bool $normalize): FrozenCall
    {
        $tool = $this->tools->get($proposal->toolName, $proposal->toolVersion);
        $audience = self::audience($trusted);
        if (
            $proposal->id === ''
            || ! $proposal->context->scope->equals($trusted->scope)
            || $proposal->context->requesterId !== $trusted->requesterId
            || $proposal->context->runtimeId !== $trusted->runtimeId
            || self::audience($proposal->context) !== $audience
        ) {
            throw new InvalidArgumentException('Call does not match trusted context.');
        }

        $context = new InvocationContext($trusted->scope, $trusted->requesterId, $trusted->runtimeId, $audience);
        $arguments = $normalize ? ($tool->normalizeArguments)($proposal->arguments, $context) : $proposal->arguments;
        $targets = ($tool->resolveTargets)($arguments, $context);
        $call = new Invocation($proposal->id, $tool->name, $tool->version, $arguments, $proposal->reason, $context, $tool->executorId);

        return new FrozenCall($call, $tool, $targets);
    }

    /** @return list<Decision>|null */
    public function evaluate(FrozenCall $frozen): ?array
    {
        if ($frozen->targets === []) {
            return null;
        }

        $context = $frozen->call->context;
        $scope = $context->scope;
        if (! $this->allowsParticipants($frozen)) {
            return null;
        }

        $owners = [];
        foreach ($frozen->targets as $target) {
            $owner = $this->authority->ownerOf($scope, $target);
            if ($owner === null || ! $this->access->includesOwner($scope, $owner)) {
                return null;
            }

            $owners[] = $owner;
        }

        $decisions = [];
        foreach ($frozen->targets as $index => $target) {
            $owner = $owners[$index];
            $rule = $this->rules->evaluate($scope, $target, $owner, $frozen->tool->actionKey);
            $decisions[] = [
                'owner_type' => $owner->type->value,
                'owner_id' => $owner->id,
                'verdict' => $rule->verdict->value,
                'rule_key' => $rule->matchedActionKey,
                'rule_revision' => $rule->revision,
            ];
        }

        return $decisions;
    }

    /**
     * @param  list<Decision>  $decisions
     * @return array<int, Requirement>|null
     */
    public function approvalRequirements(FrozenCall $frozen, array $decisions): ?array
    {
        $scope = $frozen->call->context->scope;
        $requirements = [];
        $owners = [];
        foreach ($decisions as $index => $decision) {
            if ($decision['verdict'] !== Verdict::DELEGATE->value) {
                continue;
            }

            $owner = new OwnerReference(OwnerType::from($decision['owner_type']), $decision['owner_id']);
            $eligibility = $this->eligibility($scope, $frozen->targets[$index], $owner);
            $key = $owner->type->value.':'.$owner->id;
            if ($eligibility === null || (isset($owners[$key]) && $owners[$key] !== $eligibility)) {
                return null;
            }

            $owners[$key] = $eligibility;
            $requirements[$index] = ['owner_type' => $owner->type->value, 'owner_id' => $owner->id] + $eligibility;
        }

        return self::hasDistinctApprovers($owners) ? $requirements : null;
    }

    /** @return Eligibility|null */
    private function eligibility(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?array
    {
        $rule = $this->approvers?->approvalRuleFor($scope, $resource, $owner);
        $approver = $rule === null ? $this->authority->approverFor($scope, $resource, $owner) : null;
        $people = $rule === null ? ($approver === null ? [] : [$approver]) : $rule->eligiblePeople;
        if ($people === [] || in_array('', $people, true)
            || ($owner->type === OwnerType::PERSON && $people !== [$owner->id])
            || ! $this->access->allows($scope, $scope, ...$people)) {
            return null;
        }

        return $rule === null
            ? ['approver_id' => $people[0]]
            : ['mode' => $rule->mode->value, 'eligible_people' => $people];
    }

    /** @param array<string, Eligibility> $owners */
    private static function hasDistinctApprovers(array $owners): bool
    {
        $choices = [];
        $assigned = [];
        foreach ($owners as $owner => $eligibility) {
            if (($eligibility['mode'] ?? null) === ApprovalMode::ANY->value) {
                $choices[$owner] = $eligibility['eligible_people'];

                continue;
            }

            foreach ($eligibility['eligible_people'] ?? [$eligibility['approver_id']] as $person) {
                if (isset($assigned[$person])) {
                    return false;
                }

                $assigned[$person] = $owner;
            }
        }

        foreach (array_keys($choices) as $owner) {
            $visited = [];
            if (! self::assignApprover($owner, $choices, $assigned, $visited)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $choices
     * @param  array<array-key, string>  $assigned
     * @param  array<array-key, true>  $visited
     */
    private static function assignApprover(string $owner, array $choices, array &$assigned, array &$visited): bool
    {
        foreach ($choices[$owner] ?? [] as $person) {
            if (isset($visited[$person])) {
                continue;
            }

            $visited[$person] = true;
            if (! isset($assigned[$person]) || self::assignApprover($assigned[$person], $choices, $assigned, $visited)) {
                $assigned[$person] = $owner;

                return true;
            }
        }

        return false;
    }

    /** @internal */
    public function allowsParticipants(FrozenCall $frozen): bool
    {
        $context = $frozen->call->context;

        return $this->access->allowsContext($context->scope, $context);
    }

    /** @return list<string> */
    private static function audience(InvocationContext $context): array
    {
        $audience = array_unique($context->audience, SORT_STRING);
        sort($audience, SORT_STRING);

        return $audience;
    }
}
