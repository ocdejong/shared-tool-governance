<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResumption;

final class ResumeApproval implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $invocationRowId) {}

    public function handle(ApprovalResumption $resumption): void
    {
        $resumption->resume($this->invocationRowId);
    }
}
