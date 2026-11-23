<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceAuditEvent extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }
}
