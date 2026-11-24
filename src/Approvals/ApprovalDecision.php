<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

enum ApprovalDecision: string
{
    case APPROVE = 'approve';
    case DECLINE = 'decline';
}
