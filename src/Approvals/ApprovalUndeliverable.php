<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Ocdejong\SharedToolGovernance\Data\Scope;

final readonly class ApprovalUndeliverable implements ShouldDispatchAfterCommit
{
    public const string DISTINCT_COMPLETION_IMPOSSIBLE = 'distinct_completion_impossible';

    public function __construct(
        public Scope $scope,
        public string $invocationId,
        public ?string $reason = null,
    ) {}
}
