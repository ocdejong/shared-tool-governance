<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Outbox;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPolicy;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Data\GroupApprovalPrompt;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use UnexpectedValueException;

/** @internal */
final readonly class ApprovalDelivery
{
    public function __construct(
        private ApprovalPolicy $approvals,
        private ?ApprovalNotifier $notifier = null,
    ) {}

    public function deliver(int $messageId): void
    {
        DB::transaction(function () use ($messageId): void {
            $message = GovernanceOutboxMessage::query()->find($messageId);
            if ($message === null) {
                return;
            }

            $row = ApprovalStore::locked((int) $message->getAttribute('invocation_row_id'));
            if ($row === null || $row->getAttribute('status') !== 'pending_approval' || ApprovalStore::hasCombinedLegacyCard($row)) {
                return;
            }

            // The invocation lock serializes delivery with decisions and terminal transitions.
            $message->refresh();
            if ($message->getAttribute('delivered_at') !== null || $message->getAttribute('payload') === null
                || ApprovalStore::expiresAt($row) <= now()) {
                return;
            }

            if ($this->approvals->recheck($row) === null) {
                ApprovalStore::move($row, 'revoked');

                return;
            }

            $scope = ApprovalStore::scope($row);
            $recipient = $message->getAttribute('recipient_id');
            $payload = $message->getAttribute('payload');
            $deliveryId = $message->getAttribute('delivery_id');
            $prompt = $message->getAttribute('is_group') ? new GroupApprovalPrompt($recipient, $payload) : null;
            $channel = $prompt !== null && App::bound(GroupApprovalChannel::class) ? App::make(GroupApprovalChannel::class) : null;
            if ($prompt !== null && $channel?->allowsDisclosure($scope, $prompt) !== true) {
                ApprovalStore::move($row, 'revoked');

                return;
            }

            if (ApprovalStore::expiresAt($row) <= now()) {
                return;
            }

            $cardId = $prompt !== null
                ? $channel->deliver($scope, $deliveryId, $prompt)
                : ($this->notifier ?? App::make(ApprovalNotifier::class))->deliver($scope, $deliveryId, $recipient, $payload);
            if ($cardId === '') {
                throw new UnexpectedValueException('The approval channel returned an empty card ID.');
            }

            $message->forceFill(['card_id' => $cardId, 'delivered_at' => now(), 'payload' => null])->save();
        });
    }
}
