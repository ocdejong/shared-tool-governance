<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

use Ocdejong\SharedToolGovernance\Data\Verdict;

final readonly class PermissionNode
{
    /**
     * @param  list<array{name: string, version: string}>  $tools
     * @param  list<PermissionNode>  $children
     */
    public function __construct(
        public string $key,
        public ?Verdict $explicitSetting,
        public Verdict $effectiveVerdict,
        public PermissionSource $source,
        public ?string $sourceKey,
        public string $explanation,
        public array $tools,
        public array $children,
    ) {}
}
