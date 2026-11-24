<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Ocdejong\SharedToolGovernance\Data\Scope;

final readonly class ApprovalUndeliverable implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Scope $scope,
        public string $invocationId,
    ) {}
}
