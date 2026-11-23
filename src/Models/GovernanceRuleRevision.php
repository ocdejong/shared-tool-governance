<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;

final class GovernanceRuleRevision extends Model
{
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'enabled' => 'boolean',
            'rules' => 'array',
        ];
    }
}
