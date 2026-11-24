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
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
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
        return $this->respond($approvalId, $decision, $reply)->accepted;
    }

    /** Report the card's requirement decision. Use receipt() for the status of the entire call. */
    public function respond(string $approvalId, ApprovalDecision $decision, VerifiedReply $reply): ApprovalResponse
    {
        if ($approvalId === '' || $reply->cardId === '' || $reply->replyId === '') {
            return ApprovalResponse::refused();
        }

        return DB::transaction(function () use ($approvalId, $decision, $reply): ApprovalResponse {
            $card = GovernanceOutboxMessage::query()
                ->where('delivery_id', $approvalId)
                ->where('card_id', $reply->cardId)
                ->where('recipient_id', $reply->senderId)
                ->where('delivered_at', '!=', null)
                ->first();
            if ($card === null) {
                return ApprovalResponse::refused();
            }

            $row = ApprovalStore::locked((int) $card->getAttribute('invocation_row_id'));
            if ($row === null || ! $this->access->allows(
                ApprovalStore::scope($row), $reply->scope, $reply->senderId,
                $row->getAttribute('requester_id'), ...$row->getAttribute('audience'),
            ) || ApprovalStore::hasCombinedLegacyCard($row)) {
                return ApprovalResponse::refused();
            }

            $original = ApprovalStore::approvals($row)->where('approval_id', $approvalId)
                ->where('approver_id', $reply->senderId)->first();

            return $original === null
                ? ApprovalResponse::refused()
                : self::recordDecision($row, $original, $decision, $reply);
        }, 3);
    }

    private static function recordDecision(
        GovernanceInvocation $row,
        GovernanceApproval $card,
        ApprovalDecision $decision,
        VerifiedReply $reply,
    ): ApprovalResponse {
        $requirement = ApprovalStore::approvals($row)
            ->where('owner_type', $card->getAttribute('owner_type')->value)
            ->where('owner_id', $card->getAttribute('owner_id'))
            ->get();
        $recorded = $card->getAttribute('decision');
        $duplicate = $recorded === $decision->value && $card->getAttribute('reply_id') === $reply->replyId;
        $settled = ApprovalStore::settlement($requirement);
        if ($settled !== null) {
            return ApprovalResponse::settled($settled, $duplicate);
        }
        if ($duplicate) {
            return ApprovalResponse::recorded();
        }

        if ($recorded !== null || $row->getAttribute('status') !== 'pending_approval' || ApprovalStore::expiresAt($row) <= now()
            || ApprovalStore::approvals($row)->where('approver_id', $reply->senderId)->whereNotNull('decision')->exists()) {
            return ApprovalResponse::refused();
        }

        $card->forceFill(['decision' => $decision->value, 'reply_id' => $reply->replyId, 'decided_at' => now()])->save();
        InvocationStore::audit($row, 'approval_decided', ['approval_id' => $card->getAttribute('approval_id'), 'decision' => $decision->value]);

        $status = match (true) {
            $decision === ApprovalDecision::DECLINE => 'declined',
            ApprovalStore::hasDistinctConsent($row) => 'approved',
            ! ApprovalStore::hasPossibleCompletion($row) => 'approval_undeliverable',
            default => null,
        };
        if ($status !== null) {
            ApprovalStore::move($row, $status);
            if ($status === 'approval_undeliverable') {
                event(new ApprovalUndeliverable(
                    ApprovalStore::scope($row),
                    $row->getAttribute('invocation_id'),
                    ApprovalUndeliverable::DISTINCT_COMPLETION_IMPOSSIBLE,
                ));
            } else {
                DB::afterCommit(static fn () => ApprovalWork::resume((int) $row->getKey()));
            }
        }

        $settled = ApprovalStore::settlement($requirement->map(
            static fn (GovernanceApproval $candidate): GovernanceApproval => $candidate->is($card) ? $card : $candidate,
        ));

        return $settled === null ? ApprovalResponse::recorded() : ApprovalResponse::settled($settled, true);
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
