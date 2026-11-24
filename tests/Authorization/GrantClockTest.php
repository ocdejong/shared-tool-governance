<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Grants\GrantClock;

it('reports an unsupported grant clock driver before issuing SQL', function (): void {
    $connection = $this->mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('custom');
    DB::shouldReceive('connection')->once()->andReturn($connection);

    expect(GrantClock::sql(...))->toThrow(LogicException::class, 'Grant expiry does not support database driver [custom].');
});
