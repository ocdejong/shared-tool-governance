<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final readonly class PermissionTree
{
    public function __construct(
        private ToolRegistry $registry,
        private ResourceRules $rules,
    ) {}

    public function read(Scope $scope, ResourceReference $resource): PermissionTreeSnapshot
    {
        return $this->snapshot($this->rules->getRules($scope, $resource));
    }

    /** @param array{enabled?: bool, rules?: array<string, Verdict|null>} $changes */
    public function update(Scope $scope, ResourceReference $resource, int $expectedRevision, array $changes): PermissionTreeSnapshot
    {
        return $this->snapshot($this->rules->setRules($scope, $resource, $expectedRevision, $changes));
    }

    private function snapshot(RuleSettings $settings): PermissionTreeSnapshot
    {
        $toolsByKey = [];
        foreach ($this->registry as $tool) {
            $toolsByKey[$tool->actionKey][] = ['name' => $tool->name, 'version' => $tool->version];
            $key = $tool->actionKey;
            while (($separator = strrpos($key, '.')) !== false) {
                $key = substr($key, 0, $separator);
                $toolsByKey[$key] ??= [];
            }
        }
        ksort($toolsByKey);

        $children = [];
        foreach ($toolsByKey as $key => $tools) {
            $separator = strrpos($key, '.');
            $parent = $separator === false ? '' : substr($key, 0, $separator);
            $children[$parent][] = $key;
        }

        return new PermissionTreeSnapshot($settings->revision, $settings->enabled, $this->nodes('', $settings, $toolsByKey, $children));
    }

    /**
     * @param  array<string, list<array{name: string, version: string}>>  $toolsByKey
     * @param  array<string, list<string>>  $children
     * @return list<PermissionNode>
     */
    private function nodes(string $parent, RuleSettings $settings, array $toolsByKey, array $children): array
    {
        $nodes = [];
        foreach ($children[$parent] ?? [] as $key) {
            $evaluation = $settings->evaluate($key);
            $source = match (true) {
                ! $settings->enabled => PermissionSource::RESOURCE_OFF,
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
                $this->nodes($key, $settings, $toolsByKey, $children),
            );
        }

        return $nodes;
    }
}
