<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Grants;

use Illuminate\Support\Facades\DB;
use LogicException;

/** @internal */
final class GrantClock
{
    public static function sql(int $minutesFromNow = 0): string
    {
        // PostgreSQL's CURRENT_TIMESTAMP stays fixed while a transaction waits for a lock.
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "clock_timestamp() + interval '{$minutesFromNow} minutes'",
            'sqlite' => "datetime('now', '{$minutesFromNow} minutes')",
            'mysql', 'mariadb' => "TIMESTAMPADD(MINUTE, {$minutesFromNow}, CURRENT_TIMESTAMP)",
            'sqlsrv' => "DATEADD(minute, {$minutesFromNow}, SYSDATETIMEOFFSET())",
            default => throw new LogicException("Grant expiry does not support database driver [{$driver}]."),
        };
    }
}
