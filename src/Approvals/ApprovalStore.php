<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\FrozenCall;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;

/** @phpstan-import-type Requirement from CallPolicy */
final class ApprovalStore
{
    public const int LIFETIME_MINUTES = 15;

    /** @param array<int, Requirement> $requirements */
    public static function stage(GovernanceInvocation $row, FrozenCall $frozen, array $requirements): void
    {
        $call = $frozen->call;
        $previewFor = $frozen->tool->approvalPreview;
        if ($previewFor === null) {
            throw new ApprovalSetupError($call->id, 'The delegated tool has no approval preview callback.');
        }

        $expires = self::expiresAt($row);
        $cards = [];
        foreach (array_unique(array_column($requirements, 'approver_id')) as $approver) {
            $forApprover = array_filter($requirements, static fn (array $requirement): bool => $requirement['approver_id'] === $approver);
            $targets = array_values(array_intersect_key($frozen->targets, $forApprover));
            $preview = $previewFor($call, $targets, $approver);
            if ($preview === null) {
                throw new ApprovalSetupError($call->id, 'The approval preview callback returned null.');
            }
            if (Str::trim($preview->reason) === '' || Str::trim($preview->scopeAndChange) === '') {
                throw new ApprovalSetupError($call->id, 'The approval preview requires text in both the reason and the scope and change fields.');
            }

            $cards[] = [$approver, $forApprover, self::render($frozen, $preview, $expires)];
        }

        foreach ($cards as [$approver, $forApprover, $message]) {
            $cardApprovalId = (string) Str::ulid();
            $seen = [];
            foreach ($forApprover as $owner) {
                $key = $owner['owner_type'].':'.$owner['owner_id'];
                if (isset($seen[$key])) {
                    continue;
                }

                GovernanceApproval::query()->forceCreate($owner + [
                    'invocation_row_id' => $row->getKey(),
                    'approval_id' => $seen === [] ? $cardApprovalId : (string) Str::ulid(),
                    'preview' => $seen === [] ? $message : null,
                    'expires_at' => $expires,
                ]);
                $seen[$key] = true;
            }

            GovernanceOutboxMessage::query()->forceCreate([
                'invocation_row_id' => $row->getKey(),
                'delivery_id' => $cardApprovalId,
                'recipient_id' => $approver,
                'payload' => $message,
            ]);
        }
    }

    public static function scope(GovernanceInvocation $row): Scope
    {
        return new Scope($row->getAttribute('host_scope'), $row->getAttribute('household_id'));
    }

    public static function locked(int $rowId): ?GovernanceInvocation
    {
        $query = GovernanceInvocation::query()->whereKey($rowId);
        $query->getQuery()->lockForUpdate();

        return $query->first();
    }

    /** @return Builder<GovernanceApproval> */
    public static function approvals(GovernanceInvocation $row): Builder
    {
        return GovernanceApproval::query()->where('invocation_row_id', $row->getKey());
    }

    public static function expiresAt(GovernanceInvocation $row): CarbonInterface
    {
        return $row->getAttribute('created_at')->copy()->addMinutes(self::LIFETIME_MINUTES);
    }

    public static function move(GovernanceInvocation $row, string $status): void
    {
        $changes = ['status' => $status];
        if (in_array($status, ['declined', 'expired', 'revoked', 'approved_unused'], true)) {
            $changes['frozen_inputs'] = self::auditInputs($row);
        }

        $row->forceFill($changes)->save();
        InvocationStore::audit($row, $status);

        if (in_array($status, ['declined', 'expired', 'revoked', 'approved_unused', 'granted'], true)) {
            self::approvals($row)->update(['preview' => null]);
            GovernanceOutboxMessage::query()->where('invocation_row_id', $row->getKey())->update(['payload' => null]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function auditInputs(GovernanceInvocation $row): ?array
    {
        $inputs = $row->getAttribute('frozen_inputs');
        if ($inputs === null) {
            return null;
        }

        unset($inputs['arguments_json']);

        return $inputs;
    }

    private static function render(FrozenCall $frozen, ApprovalPreview $preview, CarbonInterface $expires): string
    {
        $context = $frozen->call->context;
        $fields = [
            'Requester' => $context->requesterId,
            'Reason' => $preview->reason,
            'Scope and change' => $preview->scopeAndChange,
            'Audience' => implode(', ', $context->audience),
            'Expires' => $expires->toIso8601String(),
        ];

        $lines = [];
        foreach ($fields as $label => $value) {
            $lines[] = $label.': '.str_replace(["\r", "\n"], ' ', $value);
        }

        return implode("\n", $lines);
    }
}
