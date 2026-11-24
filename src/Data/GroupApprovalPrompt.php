<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class GroupApprovalPrompt
{
    public function __construct(
        public string $chatId,
        public string $preview,
    ) {}
}
