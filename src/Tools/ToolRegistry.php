<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tools;

use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;

/** @implements IteratorAggregate<int, ToolDefinition> */
final class ToolRegistry implements IteratorAggregate
{
    /** @var array<string, array<string, ToolDefinition>> */
    private array $tools = [];

    public function register(ToolDefinition $tool): void
    {
        if (isset($this->tools[$tool->name][$tool->version])) {
            throw new InvalidArgumentException('Tool name and version already registered.');
        }

        $this->tools[$tool->name][$tool->version] = $tool;
    }

    public function get(string $name, string $version): ToolDefinition
    {
        return $this->tools[$name][$version] ?? throw new InvalidArgumentException('Unknown tool name or version.');
    }

    /** @return Generator<int, ToolDefinition> */
    public function getIterator(): Generator
    {
        foreach ($this->tools as $versions) {
            foreach ($versions as $definition) {
                yield $definition;
            }
        }
    }
}
