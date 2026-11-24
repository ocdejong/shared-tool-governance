<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResponse;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Contracts\VerifiedReplyResolver;
use Ocdejong\SharedToolGovernance\Data\GroupApprovalPrompt;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;

final class GroupChat implements GroupApprovalChannel, VerifiedReplyResolver
{
    public const string CHAT = 'family-chat';

    private const array PREVIEWS = [
        'approve-first' => 'Move the morning appointment?',
        'decline-first' => 'Move the afternoon appointment?',
        'whole' => 'Move the family appointment?',
        'opposite' => 'Move the weekend appointment?',
        'duplicate' => 'Move the weekday appointment?',
        'retry' => 'Move the evening appointment?',
        'lost-voter' => 'Move the early appointment?',
        'audience' => 'Move the late appointment?',
        'unauthorized-chat' => 'Move the appointment?',
        'overlap' => 'Move the joint appointment?',
        'overlap-personal' => 'Move the individual appointment?',
        'named-olivier' => 'Move the first appointment?',
        'named-pauline' => 'Move the second appointment?',
        'unanimous' => 'Move both appointments?',
        'unanimous-decline' => 'Move both consultations?',
    ];

    public function __construct(private readonly Scope $scope, private readonly string $key) {}

    public function promptFor(Invocation $call, array $targets, array $eligiblePeople): ?GroupApprovalPrompt
    {
        $name = match ($targets[0]->id) {
            'overlap-personal', 'named-olivier', 'named-pauline' => $targets[0]->id,
            default => $call->id,
        };
        if ($name === 'unsafe-preview') {
            return new GroupApprovalPrompt(self::CHAT, $call->reason);
        }
        if ($name === 'disclosure-lost') {
            return new GroupApprovalPrompt(self::CHAT, 'Move the recurring appointment?');
        }
        if (! isset(self::PREVIEWS[$name])) {
            return null;
        }

        return new GroupApprovalPrompt($name === 'unauthorized-chat' ? 'public-chat' : self::CHAT, self::PREVIEWS[$name]);
    }

    public function allowsDisclosure(Scope $scope, GroupApprovalPrompt $prompt): bool
    {
        return $scope->equals($this->scope) && $prompt->chatId === self::CHAT
            && (in_array($prompt->preview, self::PREVIEWS, true) || (
                $prompt->preview === 'Move the recurring appointment?' && ! is_file(storage_path('disclosure-revoked'))
            ));
    }

    public function deliver(Scope $scope, string $deliveryId, GroupApprovalPrompt $prompt): string
    {
        $file = storage_path('group-'.$deliveryId.'.json');
        if (is_file($file)) {
            $stored = file_get_contents($file);
            if ($stored === false) {
                throw new RuntimeException('Could not read the original chat card');
            }
            [$originalChatId, $cardId, $preview] = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            check($originalChatId === $prompt->chatId && $preview === $prompt->preview, 'Retry changed the original chat card');
            check(file_put_contents(storage_path('retried-card'), $cardId) !== false, 'Could not record the retry');

            return $cardId;
        }

        $cardId = (string) Str::ulid();
        $record = json_encode([$prompt->chatId, $cardId, $prompt->preview], JSON_THROW_ON_ERROR);
        check(file_put_contents($file, $record, LOCK_EX) !== false, 'Could not post the group card');
        if ($prompt->preview === self::PREVIEWS['retry']) {
            throw new RuntimeException('Simulated lost acknowledgement after posting the chat card');
        }

        return $cardId;
    }

    /** @return array{string, string} */
    public function card(string $name): array
    {
        $matches = [];
        foreach (glob(storage_path('group-*.json')) ?: [] as $file) {
            $record = file_get_contents($file);
            if ($record === false) {
                throw new RuntimeException('Could not read the chat card');
            }
            [$chatId, $cardId, $preview] = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
            check($chatId === self::CHAT && in_array($preview, self::PREVIEWS, true), 'The chat saw a preview it could not see');
            if ($preview === self::PREVIEWS[$name]) {
                $matches[] = [substr(basename($file, '.json'), strlen('group-')), $cardId];
            }
        }
        check(count($matches) === 1, "Expected exactly one group card for $name");

        return $matches[0];
    }

    /** @param array<string, string|bool|null> $changes */
    public function event(string $name, string $sender, ApprovalDecision $decision, array $changes = []): string
    {
        [$approvalId, $cardId] = $this->card($name);
        $body = json_encode($changes + [
            'workspace' => $this->scope->workspaceId,
            'sender' => $sender,
            'chat' => self::CHAT,
            'card' => $cardId,
            'reply' => $cardId.'-'.$sender.'-'.$decision->value,
            'delivery' => $approvalId,
            'decision' => $decision->value,
            'human' => true,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $body, $this->key).'.'.$body;
    }

    public function resolve(string $eventId): ?VerifiedReply
    {
        return $this->verifiedEvent($eventId)[0] ?? null;
    }

    public function receive(string $event): ?ApprovalResponse
    {
        $verified = $this->verifiedEvent($event);
        if ($verified === null) {
            return null;
        }
        [$reply, $deliveryId, $decision] = $verified;

        return app(Approvals::class)->respond($deliveryId, $decision, $reply);
    }

    /** @return array{VerifiedReply, string, ApprovalDecision}|null */
    private function verifiedEvent(string $event): ?array
    {
        [$signature, $body] = explode('.', $event, 2) + [1 => ''];
        if (! hash_equals(hash_hmac('sha256', $body, $this->key), $signature)) {
            return null;
        }

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if ($data['human'] !== true) {
            return null;
        }

        return [
            new VerifiedReply(new Scope($this->scope->hostScope, $data['workspace']), $data['sender'], $data['card'], $data['reply'], $data['chat']),
            $data['delivery'],
            ApprovalDecision::from($data['decision']),
        ];
    }
}
