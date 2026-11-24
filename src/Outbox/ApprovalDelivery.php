<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Outbox;

use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPolicy;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use UnexpectedValueException;

/** @internal */
final readonly class ApprovalDelivery
{
    public function __construct(
        private ApprovalPolicy $approvals,
        private ApprovalNotifier $notifier,
    ) {}

    public function deliver(int $messageId): void
    {
        DB::transaction(function () use ($messageId): void {
            $message = GovernanceOutboxMessage::query()->find($messageId);
            if ($message === null) {
                return;
            }

            $row = ApprovalStore::locked((int) $message->getAttribute('invocation_row_id'));
            if ($row === null || $row->getAttribute('status') !== 'pending_approval') {
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

            if (ApprovalStore::expiresAt($row) <= now()) {
                return;
            }

            $cardId = $this->notifier->deliver(
                ApprovalStore::scope($row),
                $message->getAttribute('delivery_id'),
                $message->getAttribute('recipient_id'),
                $message->getAttribute('payload'),
            );
            if ($cardId === '') {
                throw new UnexpectedValueException('The private notifier returned an empty card ID.');
            }

            $message->forceFill(['card_id' => $cardId, 'delivered_at' => now(), 'payload' => null])->save();
        });
    }
}
