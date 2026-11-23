<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceOutboxMessage extends Model
{
    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted',
            'delivered_at' => 'immutable_datetime',
        ];
    }
}
