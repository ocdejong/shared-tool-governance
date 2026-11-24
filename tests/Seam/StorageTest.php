<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;

beforeEach(function (): void {
    expect(Schema::hasTable('governance_invocations'))->toBeFalse();
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
});

/** @param array<string, mixed> $overrides */
function fixtureInvocation(array $overrides = []): GovernanceInvocation
{
    return GovernanceInvocation::query()->forceCreate($overrides + [
        'host_scope' => 'tenant-1',
        'household_id' => 'home-1',
        'invocation_id' => 'call-1',
        'tool_name' => 'update_item',
        'tool_version' => '1',
        'action_key' => 'items.update',
        'requester_id' => 'person-1',
        'runtime_id' => 'assistant-1',
        'executor_id' => 'items-worker',
        'audience' => ['person-1'],
        'fingerprint' => hash('sha256', 'fixture'),
        'status' => 'pending_approval',
    ]);
}

it('allows the same call ID in a different tenant or household and rejects reuse in the same scope', function (): void {
    fixtureInvocation();
    fixtureInvocation(['host_scope' => 'tenant-2']);
    fixtureInvocation(['household_id' => 'home-2']);

    expect(GovernanceInvocation::query()->count())->toBe(3);
    expect(fn () => fixtureInvocation())->toThrow(UniqueConstraintViolationException::class);
});

it('allows each owner only one approval per invocation', function (): void {
    $invocation = fixtureInvocation();
    $otherInvocation = fixtureInvocation(['invocation_id' => 'call-2']);
    $approval = [
        'invocation_row_id' => $invocation->getKey(),
        'owner_type' => 'person',
        'owner_id' => 'person-1',
        'approver_id' => 'person-1',
        'expires_at' => now()->addMinutes(10),
    ];

    GovernanceApproval::query()->forceCreate(['approval_id' => (string) Str::ulid()] + $approval);
    GovernanceApproval::query()->forceCreate([
        'approval_id' => (string) Str::ulid(),
        'owner_id' => 'person-2',
        'approver_id' => 'person-2',
    ] + $approval);
    GovernanceApproval::query()->forceCreate([
        'approval_id' => (string) Str::ulid(),
        'invocation_row_id' => $otherInvocation->getKey(),
    ] + $approval);

    expect(fn () => GovernanceApproval::query()->forceCreate([
        'approval_id' => (string) Str::ulid(),
        'approver_id' => 'person-3',
    ] + $approval))->toThrow(UniqueConstraintViolationException::class);
});

it('gives an invocation one redemption slot and keeps token hashes unique', function (): void {
    $invocation = fixtureInvocation();
    $otherInvocation = fixtureInvocation(['invocation_id' => 'call-2']);
    $grant = [
        'invocation_row_id' => $invocation->getKey(),
        'token_hash' => hash('sha256', 'fixture-token'),
        'expires_at' => now()->addMinutes(5),
        'redeemed_at' => now(),
    ];

    $redeemed = GovernanceGrant::query()->forceCreate($grant);

    expect($redeemed->toArray())->not->toHaveKey('token_hash');
    expect(fn () => GovernanceGrant::query()->forceCreate([
        'token_hash' => hash('sha256', 'another-fixture-token'),
    ] + $grant))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => GovernanceGrant::query()->forceCreate([
        'invocation_row_id' => $otherInvocation->getKey(),
    ] + $grant))->toThrow(UniqueConstraintViolationException::class);
});

it('encrypts frozen inputs and private approval content and omits them from serialization', function (): void {
    $inputs = ['arguments' => ['note' => 'fixture private input']];
    $invocation = fixtureInvocation(['frozen_inputs' => $inputs]);
    $approval = GovernanceApproval::query()->forceCreate([
        'invocation_row_id' => $invocation->getKey(),
        'approval_id' => (string) Str::ulid(),
        'owner_type' => 'person',
        'owner_id' => 'person-1',
        'approver_id' => 'person-1',
        'expires_at' => now()->addMinutes(10),
        'preview' => 'fixture private preview',
    ]);
    $message = GovernanceOutboxMessage::query()->forceCreate([
        'invocation_row_id' => $invocation->getKey(),
        'delivery_id' => (string) Str::ulid(),
        'recipient_id' => 'person-1',
        'payload' => 'fixture private card',
    ]);

    expect($invocation->refresh()->getAttribute('frozen_inputs'))->toBe($inputs)
        ->and($approval->refresh()->getAttribute('preview'))->toBe('fixture private preview')
        ->and($message->refresh()->getAttribute('payload'))->toBe('fixture private card');
    expect($invocation->toArray())->not->toHaveKey('frozen_inputs');
    expect($approval->toArray())->not->toHaveKey('preview');
    expect($message->toArray())->not->toHaveKey('payload');
    expect(DB::table('governance_invocations')->value('frozen_inputs'))->not->toContain('fixture private input');
    expect(DB::table('governance_approvals')->value('preview'))->not->toContain('fixture private preview');
    expect(DB::table('governance_outbox_messages')->value('payload'))->not->toContain('fixture private card');
});
