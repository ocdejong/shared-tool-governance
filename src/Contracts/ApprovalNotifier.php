<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\Scope;

interface ApprovalNotifier
{
    /**
     * Deliver privately. Preserve the delivery ID as the approval ID for replies.
     * Return the original card ID on retries of the same delivery ID.
     */
    public function deliver(Scope $scope, string $deliveryId, string $recipientId, string $message): string;
}
