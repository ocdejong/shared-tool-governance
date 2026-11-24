<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\VerifiedReply;

interface VerifiedReplyResolver
{
    public function resolve(string $eventId): ?VerifiedReply;
}
