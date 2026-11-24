<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

use Closure;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Rules\RuleAccessDenied;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final readonly class ToolDiscovery
{
    /** @var ToolRegistry|list<ToolDefinition> */
    private ToolRegistry|array $registeredTools;

    /**
     * @param  iterable<ToolDefinition>  $registeredTools
     * @param  (Closure(InvocationContext, string, DiscoveryTarget): DiscoveryAccess)|null  $visibility
     */
    public function __construct(
        private TrustedInvocationContext $context,
        private ResourceAuthority $authority,
        private RuleEvaluator $rules,
        iterable $registeredTools,
        private ?Closure $visibility = null,
    ) {
        $this->registeredTools = $registeredTools instanceof ToolRegistry
            ? $registeredTools
            : iterator_to_array($registeredTools, preserve_keys: false);
    }

    /** @return list<string> */
    private function actionKeys(): array
    {
        $keys = [];
        foreach ($this->registeredTools as $tool) {
            $keys[] = $tool->actionKey;
        }

        return array_values(array_unique($keys));
    }

    /** @param list<DiscoveryTarget> $owners */
    public function discover(Scope $scope, array $owners, DiscoveryMode $mode = DiscoveryMode::ALL, ?string $namespace = null): DiscoveryResult
    {
        $context = $this->context->current();
        if ($scope->hostScope !== $context->scope->hostScope
            || $scope->householdId !== $context->scope->householdId
            || ! $this->authority->isMember($scope, $context->requesterId)) {
            throw new RuleAccessDenied('Discovery is unavailable in this scope.');
        }

        foreach ($owners as $target) {
            $this->verifyTarget($scope, $target);
        }

        $actions = [];
        $unresolved = [];
        foreach ($this->actionKeys() as $action) {
            if ($namespace !== null && $action !== $namespace && ! str_starts_with($action, $namespace.'.')) {
                continue;
            }

            $permissions = $this->visiblePermissions($context, $action, $owners);
            if ($permissions === []) {
                continue;
            }

            $verdicts = array_column($permissions, 'verdict');
            if ($mode === DiscoveryMode::MATCH
                && (count($permissions) !== count($owners) || in_array(Verdict::DENY, $verdicts, true))) {
                continue;
            }

            if (in_array(null, $verdicts, true)) {
                $unresolved[] = $action;
                if ($mode === DiscoveryMode::MATCH) {
                    continue;
                }
            }

            $actions[$action] = $permissions;
        }

        return new DiscoveryResult($actions, $unresolved);
    }

    private function verifyTarget(Scope $scope, DiscoveryTarget $target): void
    {
        $validOwner = match ($target->owner->type) {
            OwnerType::PERSON => $this->authority->isMember($scope, $target->owner->id),
            OwnerType::HOUSEHOLD => $target->owner->id === $scope->householdId,
        };
        if (! $validOwner) {
            throw new RuleAccessDenied('Discovery is unavailable for a selected owner or resource.');
        }

        if ($target->resource !== null) {
            $owner = $this->authority->ownerOf($scope, $target->resource);
            if ($owner?->type !== $target->owner->type || $owner->id !== $target->owner->id) {
                throw new RuleAccessDenied('Discovery is unavailable for a selected owner or resource.');
            }
        }
    }

    /**
     * @param  list<DiscoveryTarget>  $owners
     * @return list<DiscoveryPermission>
     */
    private function visiblePermissions(InvocationContext $context, string $action, array $owners): array
    {
        $permissions = [];
        foreach ($owners as $target) {
            $access = $this->visibility === null
                ? self::privateVisibility($context, $target)
                : ($this->visibility)($context, $action, $target);
            if ($access === DiscoveryAccess::HIDDEN) {
                continue;
            }

            $verdict = $access === DiscoveryAccess::VISIBLE && $target->resource !== null
                ? $this->rules->evaluate($context->scope, $target->resource, $target->owner, $action)->verdict
                : null;
            $permissions[] = new DiscoveryPermission($target->owner, $verdict);
        }

        return $permissions;
    }

    private static function privateVisibility(InvocationContext $context, DiscoveryTarget $target): DiscoveryAccess
    {
        return $target->owner->type === OwnerType::PERSON
            && $target->owner->id === $context->requesterId
            && array_diff($context->audience, [$context->requesterId]) === []
            ? DiscoveryAccess::VISIBLE
            : DiscoveryAccess::HIDDEN;
    }
}
