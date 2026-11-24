<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Authorization\ScopeAccess;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Models\GovernanceResourceBinding;
use Ocdejong\SharedToolGovernance\Models\GovernanceRuleRevision;

final readonly class ResourceRules implements RuleEvaluator
{
    private ScopeAccess $access;

    public function __construct(
        private ResourceAuthority $authority,
        private TrustedInvocationContext $context,
    ) {
        $this->access = new ScopeAccess($authority);
    }

    public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation
    {
        $verifiedOwner = $this->currentOwner($scope, $resource);
        if ($verifiedOwner?->type !== $owner->type || $verifiedOwner->id !== $owner->id) {
            return new RuleEvaluation(Verdict::DENY, null, null, 'The resource owner could not be verified.');
        }

        $binding = $this->bindingQuery($scope, $resource)->first();
        if ($binding !== null && ! $this->belongsTo($binding, $owner)) {
            return new RuleEvaluation(Verdict::DENY, null, null, 'The stored rules belong to a different owner.');
        }

        return $this->settings($binding)->evaluate($actionKey);
    }

    public function getRules(Scope $scope, ResourceReference $resource): RuleSettings
    {
        [, $owner] = $this->manager($scope, $resource);
        $binding = $this->bindingQuery($scope, $resource)->first();
        if ($binding !== null && ! $this->belongsTo($binding, $owner)) {
            throw new RuleAccessDenied('The stored rules belong to a different owner.');
        }

        return $this->settings($binding);
    }

    /** @param array{enabled?: bool, rules?: array<string, Verdict|null>} $changes */
    public function setRules(Scope $scope, ResourceReference $resource, int $expectedRevision, array $changes): RuleSettings
    {
        [$requester, $owner] = $this->manager($scope, $resource);

        return (new GovernanceResourceBinding)->getConnection()->transaction(function () use ($scope, $resource, $expectedRevision, $changes, $requester, $owner): RuleSettings {
            $now = now();
            // The unique scope key serializes competing first edits before either reads the latest revision.
            GovernanceResourceBinding::query()->insertOrIgnore([
                ...$this->bindingKey($scope, $resource),
                'owner_type' => $owner->type->value,
                'owner_id' => $owner->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $query = $this->bindingQuery($scope, $resource);
            $query->getQuery()->lockForUpdate();
            $binding = $query->firstOrFail();
            if (! $this->belongsTo($binding, $owner)) {
                throw new RuleAccessDenied('The stored rules belong to a different owner.');
            }

            $current = $this->settings($binding);
            if ($current->revision !== $expectedRevision) {
                throw new StaleRuleRevision('These rules changed after they were read.');
            }

            $rules = $current->rules;
            foreach ($changes['rules'] ?? [] as $key => $verdict) {
                if (trim($key) !== $key || in_array('', explode('.', $key), true)) {
                    throw new InvalidArgumentException('Rule keys must have non-empty namespace segments.');
                }

                if ($verdict === null) {
                    unset($rules[$key]);
                } else {
                    $rules[$key] = $verdict;
                }
            }

            $updated = new RuleSettings($current->revision + 1, $changes['enabled'] ?? $current->enabled, $rules);
            GovernanceRuleRevision::query()->forceCreate([
                'resource_binding_id' => $binding->getKey(),
                'revision' => $updated->revision,
                'enabled' => $updated->enabled,
                'rules' => array_map(static fn (Verdict $verdict): string => $verdict->value, $updated->rules),
                'edited_by' => $requester,
            ]);

            return $updated;
        }, 3);
    }

    /** @return array{string, OwnerReference} */
    private function manager(Scope $scope, ResourceReference $resource): array
    {
        $context = $this->context->current();
        $requester = $context->requesterId;
        if (! $this->access->allows($scope, $context->scope, $requester)) {
            throw new RuleAccessDenied('The requester cannot manage rules in this scope.');
        }

        $owner = $this->currentOwner($scope, $resource);
        if ($owner === null
            || ($owner->type === OwnerType::PERSON && $owner->id !== $requester)
            || ! $this->authority->canManageRules($scope, $requester, $resource, $owner)) {
            throw new RuleAccessDenied('The requester cannot manage rules for this resource.');
        }

        return [$requester, $owner];
    }

    private function currentOwner(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        $owner = $this->authority->ownerOf($scope, $resource);

        return $owner !== null && $this->access->includesOwner($scope, $owner) ? $owner : null;
    }

    /** @return array{host_scope: string, household_id: string, resource_type: string, resource_id: string} */
    private function bindingKey(Scope $scope, ResourceReference $resource): array
    {
        return [
            'host_scope' => $scope->hostScope,
            'household_id' => $scope->workspaceId,
            'resource_type' => $resource->type,
            'resource_id' => $resource->id,
        ];
    }

    /** @return Builder<GovernanceResourceBinding> */
    private function bindingQuery(Scope $scope, ResourceReference $resource): Builder
    {
        $query = GovernanceResourceBinding::query()->where($this->bindingKey($scope, $resource));
        $query->getQuery()->useWritePdo();

        return $query;
    }

    private function belongsTo(GovernanceResourceBinding $binding, OwnerReference $owner): bool
    {
        return $binding->getAttribute('owner_type') === $owner->type
            && $binding->getAttribute('owner_id') === $owner->id;
    }

    private function settings(?GovernanceResourceBinding $binding): RuleSettings
    {
        if ($binding === null) {
            return new RuleSettings(0, true, []);
        }

        $query = GovernanceRuleRevision::query()->where('resource_binding_id', $binding->getKey());
        $query->getQuery()->useWritePdo()->orderByDesc('revision');
        $latest = $query->first();

        if ($latest === null) {
            return new RuleSettings(0, true, []);
        }

        /** @var array<string, string> $stored */
        $stored = $latest->getAttribute('rules');

        return new RuleSettings(
            (int) $latest->getAttribute('revision'),
            (bool) $latest->getAttribute('enabled'),
            array_map(Verdict::from(...), $stored),
        );
    }
}
