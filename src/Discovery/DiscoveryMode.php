<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Discovery;

enum DiscoveryMode: string
{
    case ALL = 'ALL';
    case MATCH = 'MATCH';
}
