<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Database;

use Illuminate\Database\Connection;
use RuntimeException;

final class ApprovalRollback
{
    public const string ELIGIBLE_CARDS = '2026_11_24_000005_add_eligible_approval_cards';

    public const string GROUP_PROMPTS = '2026_11_24_000006_add_group_approval_prompts';

    public static function withoutEligibleCards(Connection $connection): void
    {
        if ($connection->pretending()) {
            return;
        }

        $approvals = $connection->table('governance_approvals')->useWritePdo();
        if ((clone $approvals)->whereNotNull('approval_mode')->exists()
            || $approvals->select('invocation_row_id', 'owner_type', 'owner_id')
                ->groupBy('invocation_row_id', 'owner_type', 'owner_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Governance migration 000005 would lose approval modes or distinct voters. Keep the current governance schema and compatible workers.');
        }

        if ($connection->getSchemaBuilder()->hasColumn('governance_approvals', 'group_delivery_id')) {
            self::withoutGroupPrompts($connection);
        }
    }

    public static function withoutGroupPrompts(Connection $connection): void
    {
        if ($connection->pretending()) {
            return;
        }

        if ($connection->table('governance_approvals')->useWritePdo()->whereNotNull('group_delivery_id')->exists()
            || $connection->table('governance_outbox_messages')->useWritePdo()->where('is_group', true)->exists()) {
            throw new RuntimeException('Governance migration 000006 would lose shared approval card bindings. Keep the current governance schema and compatible workers.');
        }
    }
}
