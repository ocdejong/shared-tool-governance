<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

enum Verdict: string
{
    case ALLOW = 'ALLOW';
    case DENY = 'DENY';
    case DELEGATE = 'DELEGATE';
}
