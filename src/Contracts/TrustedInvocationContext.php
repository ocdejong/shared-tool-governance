<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\InvocationContext;

interface TrustedInvocationContext
{
    public function current(): InvocationContext;
}
