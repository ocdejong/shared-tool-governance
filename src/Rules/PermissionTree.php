<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

use Closure;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Models\GovernanceResourceBinding;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final readonly class PermissionTree
{
    public function __construct(
        private ToolRegistry $registry,
        private ResourceRules $rules,
        private RuleEvaluator $policy,
    ) {}

    public function read(Scope $scope, ResourceReference $resource): PermissionTreeSnapshot
    {
        return $this->snapshot($scope, $resource, $this->rules->getRules($scope, $resource));
    }

    /** @param array{enabled?: bool, rules?: array<array-key, Verdict|null>} $changes */
    public function update(Scope $scope, ResourceReference $resource, int $expectedRevision, array $changes): PermissionTreeSnapshot
    {
        return (new GovernanceResourceBinding)->getConnection()->transaction(
            fn (): PermissionTreeSnapshot => $this->snapshot($scope, $resource, $this->rules->setRules($scope, $resource, $expectedRevision, $changes)),
            3,
        );
    }

    private function snapshot(Scope $scope, ResourceReference $resource, RuleSettings $settings): PermissionTreeSnapshot
    {
        $evaluate = $this->evaluator($scope, $resource, $settings);
        $toolsByKey = [];
        foreach ($this->registry as $tool) {
            $toolsByKey[$tool->actionKey][] = ['name' => $tool->name, 'version' => $tool->version];
            $key = $tool->actionKey;
            while (($separator = strrpos($key, '.')) !== false) {
                $key = substr($key, 0, $separator);
                $toolsByKey[$key] ??= [];
            }
        }
        ksort($toolsByKey, SORT_STRING);

        $children = [];
        foreach ($toolsByKey as $key => $tools) {
            $key = (string) $key;
            $separator = strrpos($key, '.');
            $parent = $separator === false ? '' : substr($key, 0, $separator);
            $children[$parent][] = $key;
        }

        return new PermissionTreeSnapshot($settings->revision, $settings->enabled, $this->nodes('', $settings, $evaluate, $toolsByKey, $children));
    }

    /** @return Closure(string): RuleEvaluation */
    private function evaluator(Scope $scope, ResourceReference $resource, RuleSettings $settings): Closure
    {
        if ($this->policy instanceof ResourceRules) {
            return $settings->evaluate(...);
        }

        $owner = $this->rules->managedOwner($scope, $resource);

        return fn (string $key): RuleEvaluation => $this->policy->evaluate($scope, $resource, $owner, $key);
    }

    /**
     * @param  Closure(string): RuleEvaluation  $evaluate
     * @param  array<array-key, list<array{name: string, version: string}>>  $toolsByKey
     * @param  array<array-key, list<string>>  $children
     * @return list<PermissionNode>
     */
    private function nodes(string $parent, RuleSettings $settings, Closure $evaluate, array $toolsByKey, array $children): array
    {
        $nodes = [];
        foreach ($children[$parent] ?? [] as $key) {
            $evaluation = $evaluate($key);
            $source = match (true) {
                ! $this->policy instanceof ResourceRules && $evaluation->matchedActionKey === null => PermissionSource::HOST_POLICY,
                $this->policy instanceof ResourceRules && ! $settings->enabled => PermissionSource::RESOURCE_OFF,
                $evaluation->matchedActionKey === null => PermissionSource::DEFAULT_DENY,
                $evaluation->matchedActionKey === $key => PermissionSource::EXPLICIT,
                default => PermissionSource::INHERITED,
            };
            $tools = $toolsByKey[$key];
            usort($tools, static fn (array $left, array $right): int => [$left['name'], $left['version']] <=> [$right['name'], $right['version']]);
            $nodes[] = new PermissionNode(
                $key,
                $settings->rules[$key] ?? null,
                $evaluation->verdict,
                $source,
                $evaluation->matchedActionKey,
                $evaluation->explanation,
                $tools,
                $this->nodes($key, $settings, $evaluate, $toolsByKey, $children),
            );
        }

        return $nodes;
    }
}
