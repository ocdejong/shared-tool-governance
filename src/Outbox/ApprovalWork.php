<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Outbox;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Grants\GrantClock;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;

final class ApprovalWork
{
    /** @internal */
    public static function deliverFor(int $rowId): void
    {
        self::deliverPending($rowId);
    }

    /** @internal */
    public static function resume(int $rowId): void
    {
        Bus::dispatch(new ResumeApproval($rowId));
    }

    /** Requeue committed work after lost wakeups, missed deliveries or worker restarts. */
    public function recover(): void
    {
        $model = new GovernanceInvocation;
        $now = now();
        $deadline = $model->fromDateTime($now);
        $legacyCutoff = $model->fromDateTime($now->copy()->subMinutes(ApprovalStore::LIFETIME_MINUTES));

        DB::table('governance_invocations')->useWritePdo()
            ->where(function ($query) use ($deadline, $legacyCutoff): void {
                $query->where('status', 'approved')
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending_approval')
                        ->where(fn ($due) => $due->where('approval_expires_at', '<=', $deadline)
                            ->orWhere(fn ($legacy) => $legacy->whereNull('approval_expires_at')
                                ->where('created_at', '<=', $legacyCutoff))))
                    ->orWhere(fn ($granted) => $granted->where('status', 'granted')
                        ->whereExists(fn ($approvals) => $approvals->from('governance_approvals')
                            ->whereColumn('invocation_row_id', 'governance_invocations.id'))
                        ->whereExists(fn ($grants) => $grants->from('governance_grants')
                            ->whereColumn('invocation_row_id', 'governance_invocations.id')
                            ->whereNull('redeemed_at')
                            ->where(fn ($unused) => $unused->whereNotNull('revoked_at')->orWhereRaw('expires_at <= '.GrantClock::sql()))));
            })
            ->lazyById()
            ->each(static fn (object $row) => self::resume((int) $row->id));

        self::deliverPending();
    }

    private static function deliverPending(?int $rowId = null): void
    {
        $query = GovernanceOutboxMessage::query()->where('delivered_at', null)->where('payload', '!=', null);
        $query->getQuery()->useWritePdo();
        if ($rowId !== null) {
            $query->where('invocation_row_id', $rowId);
        }

        foreach ($query->lazyById() as $message) {
            Bus::dispatch(new DeliverApproval((int) $message->getKey()));
        }
    }
}
