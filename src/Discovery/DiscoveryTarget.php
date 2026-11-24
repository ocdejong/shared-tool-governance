<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;

final readonly class DiscoveryTarget
{
    public function __construct(
        public OwnerReference $owner,
        public ?ResourceReference $resource,
    ) {}
}
