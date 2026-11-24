<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\LocalInbox;

final readonly class LocalCard
{
    public function __construct(public string $approvalId, public string $cardId, public string $message) {}
}
