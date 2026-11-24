<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

use Illuminate\Database\Eloquent\Model;
use Ocdejong\SharedToolGovernance\Data\OwnerType;

final class GovernanceResourceBinding extends Model
{
    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
        ];
    }
}
