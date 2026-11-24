<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use DomainException;

final class RedemptionRefused extends DomainException
{
    public function __construct()
    {
        parent::__construct('Grant cannot be redeemed for this call.');
    }
}
