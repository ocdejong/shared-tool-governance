<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

enum OwnerType: string
{
    case PERSON = 'person';
    case WORKSPACE = 'household';

    /** @deprecated Use WORKSPACE. */
    public const self HOUSEHOLD = self::WORKSPACE;
}
