<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Models;

/** @internal */
trait WritesTimestampOffsets
{
    public function getDateFormat(): string
    {
        // Offset-aware columns need the application's offset when the database session uses a different zone.
        return match ($this->getConnection()->getDriverName()) {
            'pgsql', 'sqlsrv' => 'Y-m-d\TH:i:sP',
            default => parent::getDateFormat(),
        };
    }
}
