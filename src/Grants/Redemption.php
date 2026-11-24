<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;

final readonly class Redemption
{
    /** @param list<ResourceReference> $targets */
    public function __construct(public Invocation $call, public array $targets) {}
}
