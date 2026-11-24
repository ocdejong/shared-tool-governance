<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;

final class DeliverApproval implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $messageId) {}

    public function handle(ApprovalDelivery $delivery): void
    {
        $delivery->deliver($this->messageId);
    }
}
