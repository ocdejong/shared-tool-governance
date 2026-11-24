<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

final readonly class ApprovalPreview
{
    public function __construct(public string $reason, public string $scopeAndChange) {}
}
