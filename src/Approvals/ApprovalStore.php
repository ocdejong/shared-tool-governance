<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\FrozenCall;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
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
        $channel = App::bound(GroupApprovalChannel::class) ? App::make(GroupApprovalChannel::class) : null;

        $expires = self::expiresAt($row);
        $targetsByOwner = [];
        $owners = [];
        foreach ($requirements as $index => $requirement) {
            $key = $requirement['owner_type'].':'.$requirement['owner_id'];
            $owners[$key] = $requirement;
            $targetsByOwner[$key][] = $frozen->targets[$index];
        }

        foreach ($owners as $key => $owner) {
            $people = $owner['effective_people'] ?? $owner['eligible_people'] ?? [$owner['approver_id']];
            $prompt = ($owner['mode'] ?? null) === ApprovalMode::ANY->value
                ? $channel?->promptFor($call, $targetsByOwner[$key], $people)
                : null;
            if ($prompt !== null) {
                if (Str::trim($prompt->chatId) === '' || Str::trim($prompt->preview) === '') {
                    throw new ApprovalSetupError($call->id, 'A group approval prompt requires a chat ID and preview.');
                }

                $deliveryId = self::stageDelivery($row, $prompt->chatId, $prompt->preview, ['is_group' => true]);
                foreach ($people as $person) {
                    self::stageVoter($row, $owner, $person, (string) Str::ulid(), ['group_delivery_id' => $deliveryId]);
                }

                continue;
            }

            if ($previewFor === null) {
                throw new ApprovalSetupError($call->id, 'The delegated tool has no approval preview callback.');
            }
            foreach ($people as $person) {
                $preview = $previewFor($call, $targetsByOwner[$key], $person);
                if ($preview === null) {
                    throw new ApprovalSetupError($call->id, 'The approval preview callback returned null.');
                }
                if (Str::trim($preview->reason) === '' || Str::trim($preview->scopeAndChange) === '') {
                    throw new ApprovalSetupError($call->id, 'The approval preview requires text in both the reason and the scope and change fields.');
                }

                $message = self::render($frozen, $preview, $expires);
                $deliveryId = self::stageDelivery($row, $person, $message);
                self::stageVoter($row, $owner, $person, $deliveryId, ['preview' => $message]);
            }
        }
    }

    /**
     * @param  Requirement  $owner
     * @param  array{preview?: string, group_delivery_id?: string}  $delivery
     */
    private static function stageVoter(GovernanceInvocation $row, array $owner, string $person, string $id, array $delivery): void
    {
        GovernanceApproval::query()->forceCreate([
            'invocation_row_id' => $row->getKey(),
            'approval_id' => $id,
            'owner_type' => $owner['owner_type'],
            'owner_id' => $owner['owner_id'],
            'approver_id' => $person,
            'approval_mode' => $owner['mode'] ?? null,
            'expires_at' => self::expiresAt($row),
        ] + $delivery);
    }

    /** @param array{is_group?: true} $channel */
    private static function stageDelivery(GovernanceInvocation $row, string $recipient, string $message, array $channel = []): string
    {
        $id = (string) Str::ulid();
        GovernanceOutboxMessage::query()->forceCreate([
            'invocation_row_id' => $row->getKey(),
            'delivery_id' => $id,
            'recipient_id' => $recipient,
            'payload' => $message,
        ] + $channel);

        return $id;
    }

    public static function hasCombinedLegacyCard(GovernanceInvocation $row): bool
    {
        return self::approvals($row)->select('approver_id')->whereNull('approval_mode')
            ->groupBy('approver_id')->havingRaw('count(*) > 1')->exists();
    }

    /** @param Collection<int, GovernanceApproval> $cards */
    public static function settlement(Collection $cards): ?ApprovalDecision
    {
        $first = $cards->first();
        if ($first === null) {
            return null;
        }

        $votes = $cards->pluck('decision')->filter();
        if ($first->getAttribute('approval_mode') === ApprovalMode::ANY) {
            $winner = $votes->first();

            return $winner === null ? null : ApprovalDecision::from($winner);
        }
        if ($votes->containsStrict(ApprovalDecision::DECLINE->value)) {
            return ApprovalDecision::DECLINE;
        }

        return $votes->count() === $cards->count() ? ApprovalDecision::APPROVE : null;
    }

    public static function hasDistinctConsent(GovernanceInvocation $row): bool
    {
        $cards = self::approvals($row)->get();
        $votes = $cards->where('decision', ApprovalDecision::APPROVE->value);
        $requirements = $row->getAttribute('frozen_inputs')['approval_requirements'] ?? [];
        if ($requirements === [] || $votes->pluck('approver_id')->duplicatesStrict()->isNotEmpty()) {
            return false;
        }

        $byOwner = self::byOwner($cards);
        $checked = [];
        foreach ($requirements as $requirement) {
            $key = $requirement['owner_type'].':'.$requirement['owner_id'];
            if (isset($checked[$key])) {
                continue;
            }

            $group = $byOwner->get($key);
            $people = $requirement['effective_people'] ?? $requirement['eligible_people'] ?? [$requirement['approver_id']];
            if ($group === null || $group->pluck('approver_id')->sort(SORT_STRING)->values()->all() !== $people
                || $group->contains(static fn (GovernanceApproval $card): bool => $card->getAttribute('approval_mode')?->value !== ($requirement['mode'] ?? null))
                || self::settlement($group) !== ApprovalDecision::APPROVE) {
                return false;
            }

            $checked[$key] = true;
        }

        return count($checked) === $byOwner->count();
    }

    public static function hasPossibleCompletion(GovernanceInvocation $row): bool
    {
        $slots = [];
        foreach (self::byOwner(self::approvals($row)->get()) as $group) {
            $people = array_values($group->map(static fn (GovernanceApproval $card): string => $card->approver_id)->all());
            if ($group->first()?->getAttribute('approval_mode') === ApprovalMode::ANY) {
                $winner = $group->firstWhere('decision', ApprovalDecision::APPROVE->value);
                $slots[] = $winner === null ? $people : [$winner->approver_id];

                continue;
            }

            foreach ($people as $person) {
                $slots[] = [$person];
            }
        }

        return ApprovalAssignments::possible($slots);
    }

    /**
     * @param  Collection<int, GovernanceApproval>  $cards
     * @return Collection<non-falsy-string, Collection<int, GovernanceApproval>>
     */
    private static function byOwner(Collection $cards): Collection
    {
        return $cards->groupBy(static fn (GovernanceApproval $card): string => $card->getAttribute('owner_type')->value.':'.$card->getAttribute('owner_id'));
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
        return $row->getAttribute('approval_expires_at')
            ?? $row->getAttribute('created_at')->copy()->addMinutes(self::LIFETIME_MINUTES);
    }

    public static function move(GovernanceInvocation $row, string $status): void
    {
        $changes = ['status' => $status];
        if (in_array($status, ['declined', 'expired', 'revoked', 'approved_unused', 'approval_undeliverable'], true)) {
            $changes['frozen_inputs'] = self::auditInputs($row);
        }

        $row->forceFill($changes)->save();
        InvocationStore::audit($row, $status);

        if (in_array($status, ['declined', 'expired', 'revoked', 'approved_unused', 'approval_undeliverable', 'granted'], true)) {
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
