<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class VerifiedReply
{
    public function __construct(
        public Scope $scope,
        public string $senderId,
        public string $cardId,
        public string $replyId,
        public ?string $chatId = null,
    ) {}
}
