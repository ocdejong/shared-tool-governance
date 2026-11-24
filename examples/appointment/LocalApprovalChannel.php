<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use LogicException;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResponse;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Data\GroupApprovalPrompt;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;

// Local CLI only. The harness supplies the authenticated fixture identity.
final class LocalApprovalChannel implements ApprovalNotifier, GroupApprovalChannel
{
    public const CHAT = 'scheduling-chat';

    public const PREVIEWS = [
        'group-approve' => 'Approve moving a customer appointment?',
        'group-decline' => 'Approve another change to a customer appointment?',
    ];

    public function promptFor(Invocation $call, array $targets, array $eligiblePeople): ?GroupApprovalPrompt
    {
        $preview = self::PREVIEWS[$call->id] ?? null;

        return $preview !== null && $targets == AppointmentServiceProvider::targets(AppointmentServiceProvider::SLOT)
            ? new GroupApprovalPrompt(self::CHAT, $preview) : null;
    }

    public function allowsDisclosure(Scope $scope, GroupApprovalPrompt $prompt): bool
    {
        return $scope->equals(AppointmentServiceProvider::scope())
            && $prompt->chatId === self::CHAT && in_array($prompt->preview, self::PREVIEWS, true);
    }

    public function deliver(Scope $scope, string $deliveryId, string|GroupApprovalPrompt $recipientId, ?string $message = null): string
    {
        if (! $scope->equals(AppointmentServiceProvider::scope())) {
            throw new LogicException('Unknown fixture workspace.');
        }

        $recipient = $recipientId instanceof GroupApprovalPrompt ? $recipientId->chatId : $recipientId;
        $preview = $recipientId instanceof GroupApprovalPrompt ? $recipientId->preview : $message;
        $cards = $this->cards($recipient);
        $card = ['card' => 'local-'.$deliveryId, 'preview' => $preview];
        if (isset($cards[$deliveryId]) && $cards[$deliveryId] !== $card) {
            throw new LogicException('A retry changed the original message.');
        }
        // The example runs one worker against a database cache.
        Cache::put('appointment.cards.'.$recipient, $cards + [$deliveryId => $card]);

        return $card['card'];
    }

    /** @return array<string, array{card: string, preview: ?string}> */
    public function cards(string $recipient): array
    {
        return Cache::get('appointment.cards.'.$recipient, []);
    }

    public function reply(
        string $sender,
        string $recipient,
        string $deliveryId,
        ApprovalDecision $decision,
    ): ApprovalResponse {
        $card = $this->cards($recipient)[$deliveryId] ?? null;
        $chat = $recipient === self::CHAT ? $recipient : null;
        $host = app(AppointmentServiceProvider::class);
        $scope = $host->current()->scope;
        if ($card === null || ! $host->isMember($scope, $sender) || ($chat === null && $sender !== $recipient)) {
            return ApprovalResponse::refused();
        }

        return app(Approvals::class)->respond($deliveryId, $decision, new VerifiedReply(
            $scope,
            $sender,
            $card['card'],
            $deliveryId.'-'.$sender.'-'.$decision->value,
            $chat,
        ));
    }
}
