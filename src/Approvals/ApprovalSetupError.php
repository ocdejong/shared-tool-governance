<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use LogicException;

final class ApprovalSetupError extends LogicException
{
    public function __construct(public readonly string $invocationId, string $message)
    {
        parent::__construct($message);
    }
}
