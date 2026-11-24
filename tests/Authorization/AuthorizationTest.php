<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Authorization\InvocationConflict;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Grants\RedemptionRefused;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Tests\Authorization\Fixture;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
});

it('rejects invalid requests and out-of-scope resources before evaluating rules', function (): void {
    $fixture = new Fixture;
    $outside = new InvocationContext(new Scope('tenant-1', 'other-home'), 'person-1', 'agent-1', ['person-1']);

    foreach ([
        $fixture->call(reason: "\u{00A0}\t"),
        $fixture->call(reason: "\u{200B}\u{2060}\u{FEFF}\u{FE0F}\u{034F}"),
        $fixture->call(tool: 'unknown'),
        $fixture->call(version: '2'),
        $fixture->call(arguments: []),
        $fixture->call(context: $outside),
    ] as $call) {
        expect(fn () => $fixture->authorizer->authorize($call))->toThrow(InvalidArgumentException::class);
    }

    expect($fixture->authorizer->authorize($fixture->call(arguments: ['date' => '2026-11-25', 'also' => 'other-home-item']))->status)->toBe('denied');
    $fixture->membersActive = false;
    expect($fixture->authorizer->authorize($fixture->call(id: 'call-2'))->status)->toBe('denied')
        ->and($fixture->evaluations)->toBe(0)
        ->and(GovernanceGrant::query()->count())->toBe(0);
});

it('lets any denial win and returns only a request ID and status when approval is needed', function (): void {
    $fixture = new Fixture;
    $fixture->verdicts = ['item-1' => Verdict::DELEGATE, 'item-2' => Verdict::DENY];
    $denial = $fixture->authorizer->authorize($fixture->call());

    $fixture->verdicts['item-2'] = Verdict::ALLOW;
    $pending = $fixture->authorizer->authorize($fixture->call(id: 'call-2'));

    expect($denial->jsonSerialize())->toBe(['status' => 'denied'])
        ->and($pending->jsonSerialize())->toBe(['status' => 'pending_approval', 'request_id' => 'call-2'])
        ->and($pending->grantToken())->toBeNull()
        ->and(GovernanceGrant::query()->count())->toBe(0);
});

it('returns the saved state on identical retries and rejects changed contents', function (): void {
    $fixture = new Fixture;
    $arguments = ['date' => '2026-11-25', 'details' => ['b' => 2, 'a' => 1]];
    $issued = $fixture->authorizer->authorize($fixture->call(arguments: $arguments));
    $fixture->verdicts['item-1'] = Verdict::DENY;
    $retry = $fixture->authorizer->authorize($fixture->call(arguments: ['details' => ['a' => 1, 'b' => 2], 'date' => '2026-11-25']));

    expect($issued->grantToken())->toBeString()
        ->and($retry->status)->toBe('granted')
        ->and($retry->grantToken())->toBeNull()
        ->and($fixture->evaluations)->toBe(2)
        ->and(GovernanceGrant::query()->count())->toBe(1);

    foreach ([
        $fixture->call(arguments: ['date' => '2026-11-26', 'details' => $arguments['details']]),
        $fixture->call(arguments: $arguments, reason: 'A different reason'),
    ] as $call) {
        expect(fn () => $fixture->authorizer->authorize($call))->toThrow(InvocationConflict::class);
    }
});

it('keeps the opaque token out of serialization, database bindings, and audit data', function (): void {
    $fixture = new Fixture;
    DB::enableQueryLog();
    $result = $fixture->authorizer->authorize($fixture->call());
    $token = $result->grantToken() ?? throw new LogicException('Expected a token.');

    $fixture->grants->redeem($token, $fixture->call());

    expect(GovernanceGrant::query()->sole()->getAttribute('token_hash'))->toBe(hash('sha256', $token))
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->toBe('{"status":"granted"}')
        ->and(serialize($result))->not->toContain($token)
        ->and(print_r($result, true))->not->toContain($token)
        ->and(var_export($result, true))->not->toContain($token)
        ->and(json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and(json_encode(DB::table('governance_audit_events')->pluck('details'), JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and(DB::table('governance_invocations')->value('frozen_inputs'))->not->toContain('Europe/Amsterdam');
});

it('runs the registered executor once after matching the approved call and audience', function (): void {
    $fixture = new Fixture;
    $this->mock(ToolExecutor::class)->shouldNotReceive('execute');
    app()->instance('other-worker', app(ToolExecutor::class));
    $call = $fixture->call(executor: 'other-worker');
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');

    expect(fn () => $fixture->grants->redeem($token, $fixture->call(arguments: ['date' => '2026-11-26'])))->toThrow(RedemptionRefused::class);
    $fixture->context = new InvocationContext($call->context->scope, 'person-1', 'agent-1', ['person-1']);
    expect(fn () => $fixture->grants->redeem($token, $fixture->call()))->toThrow(RedemptionRefused::class);
    $fixture->context = $call->context;

    expect($fixture->executions)->toBe([]);
    expect($fixture->grants->redeem($token, $fixture->call())->status)->toBe(ExecutionStatus::SUCCEEDED)
        ->and($fixture->executions)->toHaveCount(1)
        ->and($fixture->executions[0]->executorId)->toBe('items-worker')
        ->and($fixture->executions[0]->arguments)->toBe(['date' => '2026-11-25', 'timezone' => 'Europe/Amsterdam'])
        ->and($fixture->executedTargets)->toBe(['item-1', 'item-2'])
        ->and(GovernanceInvocation::query()->sole()->getAttribute('outcome'))->toBe(ExecutionStatus::SUCCEEDED);
    expect(fn () => $fixture->grants->redeem($token, $call))->toThrow(RedemptionRefused::class);
});

it('passes the stored normalized arguments to the executor with their original types and order', function (): void {
    $fixture = new Fixture;
    $proposal = $fixture->call(arguments: ['date' => '2026-11-25', 'details' => ['b' => 1.0, 'a' => 1]]);
    $actual = $fixture->call(arguments: ['details' => ['a' => 1, 'b' => 1.0], 'date' => '2026-11-25']);
    $token = $fixture->authorizer->authorize($proposal)->grantToken() ?? throw new LogicException('Expected a token.');

    $fixture->grants->redeem($token, $actual);
    expect($fixture->executions[0]->arguments)->toBe($proposal->arguments + ['timezone' => 'Europe/Amsterdam']);
});

it('checks revocation, expiry, current ownership, membership and rules before consuming', function (string $change): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');

    match ($change) {
        'expired' => DB::table('governance_grants')->update(['expires_at' => '2000-01-01 00:00:00']),
        'revoked' => GovernanceGrant::query()->update(['revoked_at' => now()]),
        'owner' => $fixture->owner = 'person-1',
        'membership' => $fixture->membersActive = false,
        'denied' => $fixture->verdicts['item-1'] = Verdict::DENY,
        'delegated' => $fixture->verdicts['item-1'] = Verdict::DELEGATE,
        default => throw new LogicException('Unknown fixture change.'),
    };

    expect(fn () => $fixture->grants->redeem($token, $call))->toThrow(RedemptionRefused::class)
        ->and(GovernanceGrant::query()->sole()->getAttribute('redeemed_at'))->toBeNull()
        ->and($fixture->executions)->toBe([]);
    if ($change === 'expired') {
        expect($fixture->evaluations)->toBe(2);
    }
})->with(['expired', 'revoked', 'owner', 'membership', 'denied', 'delegated']);

it('requires redemption to commit before executing the approved call', function (): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');

    expect(fn () => DB::transaction(fn () => $fixture->grants->redeem($token, $call)))
        ->toThrow(DomainException::class, 'Redemption must commit before the executor can act.');
    expect($fixture->executions)->toBe([]);
    $fixture->grants->redeem($token, $call);
    expect($fixture->executions[0]->id)->toBe($call->id);
});

it('reconciles an unknown downstream outcome and deduplicates identical reports', function (): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');

    expect(fn () => $fixture->grants->recordOutcome($call->id, ExecutionStatus::UNKNOWN))->toThrow(DomainException::class);
    $fixture->outcome = ExecutionStatus::UNKNOWN;
    $fixture->grants->redeem($token, $call);

    $fixture->grants->recordOutcome($call->id, ExecutionStatus::UNKNOWN);
    $fixture->grants->recordOutcome($call->id, ExecutionStatus::UNKNOWN);

    expect(GovernanceInvocation::query()->sole()->getAttribute('outcome'))->toBe(ExecutionStatus::UNKNOWN)
        ->and(DB::table('governance_audit_events')->orderBy('id')->pluck('event')->all())->toBe(['granted', 'redeemed', 'outcome_recorded']);

    $fixture->grants->recordOutcome($call->id, ExecutionStatus::SUCCEEDED, 'fixture-receipt');
    expect(fn () => $fixture->grants->recordOutcome($call->id, ExecutionStatus::FAILED))->toThrow(DomainException::class)
        ->and(GovernanceInvocation::query()->sole()->getAttribute('outcome'))->toBe(ExecutionStatus::SUCCEEDED);
});

it('lets exactly one of two PostgreSQL connections redeem the same grant', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the dedicated PostgreSQL test database.');
    }

    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');
    DB::purge();

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not open a socket pair.');
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not start the second redemption process.');
    }

    $socket = $sockets[$pid === 0 ? 1 : 0];
    fclose($sockets[$pid === 0 ? 0 : 1]);
    stream_set_timeout($socket, 10);

    $atLock = false;
    DB::connection()->beforeExecuting(static function (string $sql) use ($socket, &$atLock): void {
        if ($atLock || ! str_contains($sql, '"governance_invocations"') || ! str_contains($sql, 'for update')) {
            return;
        }

        $atLock = true;
        if (fwrite($socket, 'R') !== 1 || fread($socket, 1) !== 'R') {
            throw new RuntimeException('Both connections must reach the invocation lock.');
        }
    });

    try {
        $fixture->grants->redeem($token, $call);
        $result = 'G';
    } catch (RedemptionRefused) {
        $result = 'D';
    } catch (Throwable $error) {
        $result = 'E';
        if ($pid !== 0) {
            throw $error;
        }
    } finally {
        if ($pid === 0) {
            fwrite($socket, $result);
            fclose($socket);
            exit($result === 'E' ? 1 : 0);
        }

        $peer = fread($socket, 1);
        fclose($socket);
        pcntl_waitpid($pid, $status);
    }

    expect(pcntl_wifexited($status))->toBeTrue()
        ->and(pcntl_wexitstatus($status))->toBe(0)
        ->and(in_array($result.$peer, ['GD', 'DG'], true))->toBeTrue()
        ->and(DB::table('governance_audit_events')->where('event', 'redeemed')->count())->toBe(1);
});

it('denies invalid targets or audience before evaluating any rules', function (string $change): void {
    $fixture = new Fixture;
    $arguments = ['date' => '2026-11-25'];
    match ($change) {
        'no targets' => $arguments['no_targets'] = true,
        'foreign household owner' => $fixture->householdOwner = 'other-household',
        'audience member only' => $fixture->context = new InvocationContext(
            $fixture->context->scope, 'person-1', 'agent-1', ['person-1', 'outsider'],
        ),
        default => throw new LogicException('Unknown fixture change.'),
    };

    expect($fixture->authorizer->authorize($fixture->call(arguments: $arguments))->status)->toBe('denied')
        ->and($fixture->evaluations)->toBe(0)
        ->and(GovernanceGrant::query()->count())->toBe(0)
        ->and($fixture->executions)->toBe([]);
})->with(['no targets', 'foreign household owner', 'audience member only']);

it('returns the winning authorization after a concurrent insert', function (bool $sameCall): void {
    $fixture = new Fixture;
    $attempted = false;
    $winner = null;
    DB::connection()->beforeStartingTransaction(function () use ($fixture, $sameCall, &$attempted, &$winner): void {
        if ($attempted) {
            return;
        }

        $attempted = true;
        $winner = $fixture->authorizer->authorize($fixture->call(reason: $sameCall ? 'Move the repair visit' : 'A different reason'));
    });

    if ($sameCall) {
        $retry = $fixture->authorizer->authorize($fixture->call());
        expect($retry->status)->toBe('granted')->and($retry->grantToken())->toBeNull();
    } else {
        expect(fn () => $fixture->authorizer->authorize($fixture->call()))->toThrow(InvocationConflict::class);
    }

    expect($winner?->grantToken())->toBeString()
        ->and(GovernanceInvocation::query()->count())->toBe(1)
        ->and(GovernanceGrant::query()->count())->toBe(1);
})->with([true, false]);

it('refuses a token that does not belong to the exact scoped invocation', function (string $source): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $ownToken = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');
    $token = str_repeat('0', 64);
    if ($source !== 'wrong token') {
        if ($source === 'another household') {
            $fixture->context = new InvocationContext(new Scope('tenant-1', 'home-2'), 'person-1', 'agent-1', ['person-1', 'person-2']);
        }

        $otherCall = $fixture->call(id: $source === 'another household' ? $call->id : 'call-2');
        $token = $fixture->authorizer->authorize($otherCall)->grantToken() ?? throw new LogicException('Expected a token.');
        $fixture->context = $call->context;
    }

    expect(fn () => $fixture->grants->redeem($token, $call))->toThrow(RedemptionRefused::class)
        ->and($fixture->executions)->toBe([])
        ->and(GovernanceGrant::query()->whereNotNull('redeemed_at')->count())->toBe(0);
    expect($fixture->grants->redeem($ownToken, $call)->status)->toBe(ExecutionStatus::SUCCEEDED);
})->with(['wrong token', 'another invocation', 'another household']);

it('uses the database clock for grant issuance and redemption in a non-UTC PostgreSQL session', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires the dedicated PostgreSQL test database.');
    }

    DB::statement("SET TIME ZONE 'America/New_York'");
    $fixture = new Fixture;
    $call = $fixture->call();
    $this->travel(1)->days();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');
    expect((int) DB::table('governance_grants')
        ->selectRaw('extract(epoch from expires_at - clock_timestamp()) as lifetime')->value('lifetime'))
        ->toBeBetween(295, 301);

    $fixture->grants->redeem($token, $call);
    expect($fixture->executions)->toHaveCount(1)
        ->and((int) DB::table('governance_grants')
            ->selectRaw('abs(extract(epoch from clock_timestamp() - redeemed_at)) as age')->value('age'))->toBeLessThan(5);

    $this->travelBack();
    $this->travel(-1)->days();
    $expiredCall = $fixture->call(id: 'expired-call');
    $expiredToken = $fixture->authorizer->authorize($expiredCall)->grantToken() ?? throw new LogicException('Expected a token.');
    $expiredRow = GovernanceInvocation::query()->where('invocation_id', 'expired-call')->sole();
    DB::table('governance_grants')->where('invocation_row_id', $expiredRow->getKey())
        ->update(['expires_at' => DB::raw("clock_timestamp() - interval '1 second'")]);

    expect(fn () => $fixture->grants->redeem($expiredToken, $expiredCall))->toThrow(RedemptionRefused::class)
        ->and($fixture->executions)->toHaveCount(1);
});

it('refuses a grant that expires between locking and consuming it', function (): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');
    $fixture->onEvaluate = static function (): void {
        DB::table('governance_grants')->update(['expires_at' => '2000-01-01 00:00:00']);
    };

    expect(fn () => $fixture->grants->redeem($token, $call))->toThrow(RedemptionRefused::class)
        ->and($fixture->executions)->toBe([])
        ->and(GovernanceGrant::query()->sole()->getAttribute('redeemed_at'))->toBeNull();
});

it('keeps an interactive grant usable when executor resolution fails', function (bool $bound): void {
    $fixture = new Fixture;
    $call = $fixture->call();
    $token = $fixture->authorizer->authorize($call)->grantToken() ?? throw new LogicException('Expected a token.');
    app()->forgetInstance('items-worker');
    if ($bound) {
        app()->instance('items-worker', new stdClass);
    }

    expect(fn () => $fixture->grants->redeem($token, $call))
        ->toThrow($bound ? InvalidArgumentException::class : BindingResolutionException::class)
        ->and($fixture->executions)->toBe([])
        ->and(GovernanceGrant::query()->sole()->getAttribute('redeemed_at'))->toBeNull()
        ->and(GovernanceInvocation::query()->sole()->getAttribute('outcome'))->toBeNull();

    app()->instance('items-worker', $fixture);
    expect($fixture->grants->redeem($token, $call)->status)->toBe(ExecutionStatus::SUCCEEDED)
        ->and($fixture->executions)->toHaveCount(1);
})->with(['wrong type' => true, 'missing binding' => false]);
