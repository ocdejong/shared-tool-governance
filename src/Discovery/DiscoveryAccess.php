<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

enum DiscoveryAccess
{
    case VISIBLE;
    case UNRESOLVED;
    case HIDDEN;
}
