<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceOutboxMessage extends Model
{
    use WritesTimestampOffsets;

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'is_group' => 'boolean',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
