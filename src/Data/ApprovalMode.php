<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

enum ApprovalMode: string
{
    case ANY = 'any';
    case ALL = 'all';
}
