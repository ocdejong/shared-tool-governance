<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;

final class GovernanceInvocation extends Model
{
    use WritesTimestampOffsets;

    protected $hidden = ['reason', 'frozen_inputs', 'receipt_targets', 'fingerprint', 'provider_reference'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'frozen_inputs' => 'encrypted:array',
            'receipt_targets' => 'encrypted:array',
            'outcome' => ExecutionStatus::class,
            'outcome_recorded_at' => 'immutable_datetime',
            'approval_expires_at' => 'immutable_datetime',
        ];
    }
}
