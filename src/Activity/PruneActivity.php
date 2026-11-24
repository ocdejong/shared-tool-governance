<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Activity;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Configuration;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;

/** @internal */
final class PruneActivity
{
    public function prune(): int
    {
        $now = now();
        $cutoff = $now->copy()->subDays(Configuration::duration('retention_days'));
        // The timestamp format used for invocation rows starts at year 1.
        if ($cutoff->year < 1) {
            return 0;
        }

        $candidates = GovernanceInvocation::query();
        $candidates->where('created_at', '<=', $candidates->getModel()->fromDateTime($cutoff));
        $candidates->getQuery()->whereNotNull('frozen_inputs')->useWritePdo();

        $count = 0;
        foreach ($candidates->lazyById() as $row) {
            $count += (int) DB::transaction(
                static fn (): bool => self::pruneOne((int) $row->getKey(), $now, $cutoff),
                3,
            );
        }

        return $count;
    }

    private static function pruneOne(int $rowId, CarbonInterface $now, CarbonInterface $cutoff): bool
    {
        $row = ApprovalStore::locked($rowId);
        if ($row === null || $row->getRawOriginal('frozen_inputs') === null) {
            return false;
        }

        $state = ActivityState::read($row, $now);
        if ($state->terminalAt === null || $state->terminalAt > $cutoff) {
            return false;
        }

        if ($state->status !== $row->getAttribute('status')) {
            ApprovalStore::move($row, $state->status);
        } else {
            ApprovalStore::approvals($row)->update(['preview' => null]);
            GovernanceOutboxMessage::query()->where('invocation_row_id', $rowId)->update(['payload' => null]);
        }

        $changes = ['receipt_targets' => ReceiptTargets::read($row), 'frozen_inputs' => null];
        if ($row->getRawOriginal('reason') !== null) {
            $changes['reason'] = null;
        }
        $row->forceFill($changes)->save();

        return true;
    }
}
