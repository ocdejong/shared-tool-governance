<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\Verdict;

final readonly class DiscoveryPermission
{
    public function __construct(
        public OwnerReference $owner,
        public ?Verdict $verdict,
    ) {}
}
