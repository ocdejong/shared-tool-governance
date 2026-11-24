<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final readonly class CallPolicy
{
    public function __construct(
        private ToolRegistry $tools,
        private TrustedInvocationContext $context,
        private ResourceAuthority $authority,
        private RuleEvaluator $rules,
    ) {}

    public function freeze(Invocation $proposal): FrozenCall
    {
        if (preg_match('/\S/u', $proposal->reason) !== 1) {
            throw new InvalidArgumentException('A non-blank reason is required.');
        }

        $tool = $this->tools->get($proposal->toolName, $proposal->toolVersion);
        $trusted = $this->context->current();
        $audience = self::audience($trusted);
        if (
            $proposal->id === ''
            || $proposal->executorId !== $tool->executorId
            || $proposal->context->scope->hostScope !== $trusted->scope->hostScope
            || $proposal->context->scope->householdId !== $trusted->scope->householdId
            || $proposal->context->requesterId !== $trusted->requesterId
            || $proposal->context->runtimeId !== $trusted->runtimeId
            || self::audience($proposal->context) !== $audience
        ) {
            throw new InvalidArgumentException('Call does not match the registered executor or trusted context.');
        }

        $context = new InvocationContext($trusted->scope, $trusted->requesterId, $trusted->runtimeId, $audience);
        $arguments = ($tool->normalizeArguments)($proposal->arguments, $context);
        $targets = ($tool->resolveTargets)($arguments, $context);
        $call = new Invocation($proposal->id, $tool->name, $tool->version, $arguments, $proposal->reason, $context, $tool->executorId);

        return new FrozenCall($call, $tool, $targets);
    }

    /**
     * @return list<array{owner_type: string, owner_id: string, verdict: string, rule_key: string|null, rule_revision: int|null}>|null
     */
    public function evaluate(FrozenCall $frozen): ?array
    {
        if ($frozen->targets === []) {
            return null;
        }

        $context = $frozen->call->context;
        $scope = $context->scope;
        foreach (array_unique([$context->requesterId, ...$context->audience], SORT_STRING) as $person) {
            if (! $this->authority->isMember($scope, $person)) {
                return null;
            }
        }

        $owners = [];
        foreach ($frozen->targets as $target) {
            $owner = $this->authority->ownerOf($scope, $target);
            if ($owner === null || match ($owner->type) {
                OwnerType::PERSON => ! $this->authority->isMember($scope, $owner->id),
                OwnerType::HOUSEHOLD => $owner->id !== $scope->householdId,
            }) {
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

    /** @return list<string> */
    private static function audience(InvocationContext $context): array
    {
        $audience = array_unique($context->audience, SORT_STRING);
        sort($audience, SORT_STRING);

        return $audience;
    }
}
