<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

final readonly class DiscoveryResult
{
    /**
     * @param  array<string, list<DiscoveryPermission>>  $actions
     * @param  list<string>  $unresolved
     */
    public function __construct(
        public array $actions,
        public array $unresolved,
    ) {}
}
