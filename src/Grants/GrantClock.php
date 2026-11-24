<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use Illuminate\Support\Facades\DB;

/** @internal */
final class GrantClock
{
    public static function sql(int $minutesFromNow = 0): string
    {
        // PostgreSQL's CURRENT_TIMESTAMP stays fixed while a transaction waits for a lock.
        return DB::connection()->getDriverName() === 'pgsql'
            ? "clock_timestamp() + interval '{$minutesFromNow} minutes'"
            : "datetime('now', '{$minutesFromNow} minutes')";
    }
}
