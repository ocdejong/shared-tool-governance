<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Activity\ActivityState;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Authorization\ScopeAccess;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;

final readonly class Approvals
{
    private ScopeAccess $access;

    public function __construct(ResourceAuthority $authority, private TrustedInvocationContext $context)
    {
        $this->access = new ScopeAccess($authority);
    }

    public function decide(string $approvalId, ApprovalDecision $decision, VerifiedReply $reply): bool
    {
        if ($approvalId === '' || $reply->cardId === '' || $reply->replyId === '') {
            return false;
        }

        return DB::transaction(function () use ($approvalId, $decision, $reply): bool {
            $card = GovernanceOutboxMessage::query()
                ->where('delivery_id', $approvalId)
                ->where('card_id', $reply->cardId)
                ->where('recipient_id', $reply->senderId)
                ->where('delivered_at', '!=', null)
                ->first();
            if ($card === null) {
                return false;
            }

            $row = ApprovalStore::locked((int) $card->getAttribute('invocation_row_id'));
            if ($row === null || ! $this->access->allows(
                ApprovalStore::scope($row), $reply->scope, $reply->senderId,
                $row->getAttribute('requester_id'), ...$row->getAttribute('audience'),
            )) {
                return false;
            }

            $approvals = ApprovalStore::approvals($row)->where('approver_id', $reply->senderId);
            $original = (clone $approvals)->where('approval_id', $approvalId)->first();
            if ($original === null) {
                return false;
            }

            $previous = $original->getAttribute('decision');
            if ($previous !== null) {
                return $previous === $decision->value && $original->getAttribute('reply_id') === $reply->replyId;
            }

            $now = now();
            if ($row->getAttribute('status') !== 'pending_approval' || ApprovalStore::expiresAt($row) <= $now) {
                return false;
            }

            $approvals->whereNull('decision')->update([
                'decision' => $decision->value,
                'reply_id' => $reply->replyId,
                'decided_at' => $approvals->getModel()->fromDateTime($now),
            ]);
            InvocationStore::audit($row, 'approval_decided', ['approval_id' => $approvalId, 'decision' => $decision->value]);

            $status = match (true) {
                $decision === ApprovalDecision::DECLINE => 'declined',
                ! ApprovalStore::approvals($row)->whereNull('decision')->exists() => 'approved',
                default => null,
            };
            if ($status !== null) {
                ApprovalStore::move($row, $status);
                DB::afterCommit(static fn () => ApprovalWork::resume((int) $row->getKey()));
            }

            return true;
        }, 3);
    }

    /** @return array{status: string, outcome?: string}|null Requester-only status, without provider data. */
    public function receipt(string $invocationId): ?array
    {
        $context = $this->context->current();
        if (! $this->access->allowsContext($context->scope, $context)) {
            return null;
        }

        $query = InvocationStore::query($context->scope, $invocationId)
            ->where('requester_id', $context->requesterId)
            ->where('runtime_id', $context->runtimeId);
        $query->getQuery()->useWritePdo();
        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $state = ActivityState::read($row, now());
        $receipt = ['status' => $state->status];

        return $state->outcome === null ? $receipt : $receipt + ['outcome' => $state->outcome->value];
    }
}
