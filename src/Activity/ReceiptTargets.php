<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Activity;

use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;

/**
 * @internal
 *
 * @phpstan-type Target array{type: string, id: string, revision: string|null, accountAlias: string|null, owner_type: string, owner_id: string, verdict: string, rule_key: string|null, rule_revision: int|null}
 */
final class ReceiptTargets
{
    /** @return list<Target> */
    public static function read(GovernanceInvocation $row): array
    {
        $retained = $row->getAttribute('receipt_targets');
        if ($retained !== null) {
            return $retained;
        }

        $inputs = $row->getAttribute('frozen_inputs');
        $targets = [];
        foreach ($inputs['decisions'] ?? [] as $index => $decision) {
            $target = $inputs['targets'][$index];
            $targets[] = [
                'type' => $target['type'],
                'id' => $target['id'],
                'revision' => $target['revision'],
                'accountAlias' => $target['accountAlias'],
                'owner_type' => $decision['owner_type'],
                'owner_id' => $decision['owner_id'],
                'verdict' => $decision['verdict'],
                'rule_key' => $decision['rule_key'],
                'rule_revision' => $decision['rule_revision'],
            ];
        }

        return $targets;
    }
}
