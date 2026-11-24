<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\VerifiedReply;

interface VerifiedReplyResolver
{
    /** Resolve a trusted inbound event to its human sender and original card, with the chat ID for group replies. */
    public function resolve(string $eventId): ?VerifiedReply;
}
