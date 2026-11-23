<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('registers the package migrations with artisan and rolls them back', function (): void {
    $tables = [
        'governance_resource_bindings',
        'governance_rule_revisions',
        'governance_invocations',
        'governance_approvals',
        'governance_grants',
        'governance_outbox_messages',
        'governance_audit_events',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());

    try {
        foreach ($tables as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    } finally {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    }

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});

it('forbids unfaked HTTP requests', function (): void {
    Http::fake(['fixture.test/*' => Http::response('fixture')]);

    Http::get('https://fixture.test/');
    Http::assertSentCount(1);
    expect(fn () => Http::get('https://unfaked.invalid/'))->toThrow(StrayRequestException::class);
});
