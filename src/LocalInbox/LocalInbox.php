<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\LocalInbox;

use Illuminate\Support\Str;
use LogicException;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Data\Scope;

/** In-memory private notifier for local consumers and tests. */
final class LocalInbox implements ApprovalNotifier
{
    /** @var array<string, array{Scope, string, LocalCard}> */
    private array $deliveries = [];

    public function deliver(Scope $scope, string $deliveryId, string $recipientId, string $message): string
    {
        [$previousScope, $previousRecipient, $card] = $this->deliveries[$deliveryId] ?? [
            $scope, $recipientId, new LocalCard($deliveryId, (string) Str::ulid(), $message),
        ];
        if (! $previousScope->equals($scope) || $previousRecipient !== $recipientId || $card->message !== $message) {
            throw new LogicException('This delivery ID is already bound to another private card.');
        }

        $this->deliveries[$deliveryId] = [$scope, $recipientId, $card];

        return $card->cardId;
    }

    /** @return list<LocalCard> */
    public function cards(Scope $scope, string $recipientId): array
    {
        $cards = [];
        foreach ($this->deliveries as [$deliveredScope, $recipient, $card]) {
            if ($deliveredScope->equals($scope) && $recipient === $recipientId) {
                $cards[] = $card;
            }
        }

        return $cards;
    }
}
