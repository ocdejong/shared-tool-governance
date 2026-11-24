<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceGrant extends Model
{
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
        ];
    }
}
