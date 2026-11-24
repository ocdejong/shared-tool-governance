<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

final readonly class PermissionTreeSnapshot
{
    /** @param list<PermissionNode> $nodes */
    public function __construct(
        public int $revision,
        public bool $enabled,
        public array $nodes,
    ) {}
}
