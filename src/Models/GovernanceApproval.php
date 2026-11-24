<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\OwnerType;

final class GovernanceApproval extends Model
{
    use WritesTimestampOffsets;

    protected $hidden = ['preview'];

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'approval_mode' => ApprovalMode::class,
            'preview' => 'encrypted',
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
