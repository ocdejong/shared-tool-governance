<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

enum ExecutionStatus: string
{
    case SUCCEEDED = 'success';
    case FAILED = 'failure';
    case UNKNOWN = 'unknown';
}
