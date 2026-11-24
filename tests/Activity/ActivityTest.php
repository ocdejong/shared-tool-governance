<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResumption;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Grants\RedemptionRefused;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake();
});

function activityViewer(ApprovalHost $host, string $person): void
{
    $host->context = new InvocationContext($host->context->scope, $person, 'activity-ui', [$person]);
}

function deliverActivityCards(): void
{
    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));
}

function answerActivityCard(ApprovalHost $host, string $person): bool
{
    $scope = $host->context->scope;
    $card = $host->inbox->cards($scope, $person)[0];

    return app(Approvals::class)->decide($card->approvalId, ApprovalDecision::APPROVE, new VerifiedReply($scope, $person, $card->cardId, "reply-$person"));
}

it('shows only the targets each viewer can manage in their current scope', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    $host->members[] = 'bystander';
    $original = $host->context;
    $authorize = app(Authorizer::class);
    $authorize->authorize($host->call('alice-only'));
    $authorize->authorize($host->call('both', two: true));

    $activity = app(Activity::class);
    activityViewer($host, 'alice');
    $alice = $activity->recentUse();
    expect(array_column($alice, 'invocation_id'))->toBe(['both', 'alice-only'])
        ->and(array_column($alice[0]['targets'] ?? [], 'id'))->toBe(['personal']);

    activityViewer($host, 'bob');
    $bob = $activity->recentUse();
    expect(array_column($bob, 'invocation_id'))->toBe(['both'])
        ->and(array_column($bob[0]['targets'] ?? [], 'id'))->toBe(['shared'])
        ->and($activity->revoke('alice-only'))->toBeFalse();

    foreach (['bystander', 'outsider'] as $person) {
        activityViewer($host, $person);
        expect($activity->recentUse())->toBe([])
            ->and($activity->revoke('both'))->toBeFalse();
    }

    activityViewer($host, 'requester');
    expect($activity->recentUse('both'))->toBe([['status' => 'pending_approval', 'requester_id' => 'requester', 'approvals' => [], 'reason' => $host->call()->reason]]);
    $host->context = new InvocationContext(new Scope('another-tenant', 'home'), 'alice', 'activity-ui', ['alice']);
    expect($activity->recentUse())->toBe([])
        ->and($activity->revoke('both'))->toBeFalse();

    $host->context = $original;
    $host->personalOwner = 'bob';
    activityViewer($host, 'bob');
    expect(array_column($activity->recentUse(), 'invocation_id'))->toBe(['both'])
        ->and($activity->revoke('alice-only'))->toBeFalse();
});

it('shows decided approvals and the outcome according to the viewer', function (string $viewer, array $approvers): void {
    $host = new ApprovalHost;
    $this->freezeSecond();
    $host->members[] = 'bystander';
    $host->bind();
    $requester = $host->context;
    $call = $host->call(two: true);
    app(Authorizer::class)->authorize($call);
    deliverActivityCards();
    expect(answerActivityCard($host, 'alice'))->toBeTrue();
    $aliceDecidedAt = now()->toIso8601String();

    $activity = app(Activity::class);
    activityViewer($host, $viewer);
    $waiting = $activity->recentUse($call->id);
    if ($viewer === 'requester') {
        expect($waiting)->toBe([[
            'status' => 'pending_approval',
            'requester_id' => 'requester',
            'approvals' => [['approver_id' => 'alice', 'decision' => 'approve', 'decided_at' => $aliceDecidedAt]],
            'reason' => $call->reason,
        ]]);
    }

    $host->context = $requester;
    expect(answerActivityCard($host, 'bob'))->toBeTrue();
    $bobDecidedAt = now()->toIso8601String();
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
    expect(app(Approvals::class)->receipt($call->id))->toBe(['status' => 'granted', 'outcome' => 'success']);

    activityViewer($host, $viewer);
    $receipts = $activity->recentUse($call->id);
    if ($viewer === 'bystander') {
        expect($waiting)->toBe([])->and($receipts)->toBe([]);

        return;
    }

    $decidedAt = ['alice' => $aliceDecidedAt, 'bob' => $bobDecidedAt];
    $expected = [
        'status' => 'granted',
        'outcome' => 'success',
        'requester_id' => 'requester',
        'approvals' => array_map(static fn (string $person): array => [
            'approver_id' => $person,
            'decision' => 'approve',
            'decided_at' => $decidedAt[$person],
        ], $approvers),
    ];
    expect($receipts)->toHaveCount(1)->and($receipts[0])->toMatchArray($expected);
    if ($viewer === 'requester') {
        expect($receipts[0])->toBe($expected + ['reason' => $call->reason]);
    } else {
        expect($waiting[0]['approvals'] ?? null)->toBe($viewer === 'alice' ? $expected['approvals'] : [])
            ->and(array_keys($receipts[0]))->toEqualCanonicalizing([
                ...array_keys($expected), 'invocation_id', 'action', 'requested_at', 'targets',
            ])
            ->and(array_column($receipts[0]['targets'] ?? [], 'id'))->toBe([$viewer === 'alice' ? 'personal' : 'shared']);
    }

    $this->travel(31)->days();
    expect(Artisan::call('shared-tool-governance:prune'))->toBe(0);
    unset($receipts[0]['reason']);
    expect($activity->recentUse($call->id))->toBe($receipts);
})->with([
    'requester' => ['requester', ['alice', 'bob']],
    'person owner' => ['alice', ['alice']],
    'household manager' => ['bob', ['bob']],
    'non-participant member' => ['bystander', []],
]);

it('revokes a waiting call or queued resumption without executing', function (string $revoker, bool $fullyApproved): void {
    $host = new ApprovalHost;
    $host->bind();
    $original = $host->context;
    app(Authorizer::class)->authorize($host->call(two: true));
    deliverActivityCards();
    expect(answerActivityCard($host, 'alice'))->toBeTrue();
    if ($fullyApproved) {
        expect(answerActivityCard($host, 'bob'))->toBeTrue();
    }

    activityViewer($host, $revoker);
    $activity = app(Activity::class);
    expect($activity->revoke('visit'))->toBeTrue()
        ->and($activity->revoke('visit'))->toBeFalse();
    $host->context = $original;
    if (! $fullyApproved) {
        expect(answerActivityCard($host, 'bob'))->toBeFalse();
    }
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
    expect($host->executions)->toBe([])
        ->and($activity->recentUse('visit')[0]['status'] ?? null)->toBe('revoked')
        ->and(DB::table('governance_grants')->count())->toBe(0);
})->with([['requester', false], ['alice', false], ['bob', true]]);

it('revokes a pending call on resumption when its frozen reason is missing', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    app(Authorizer::class)->authorize($host->call());
    deliverActivityCards();
    $row = GovernanceInvocation::query()->firstOrFail();
    $inputs = $row->getAttribute('frozen_inputs');
    unset($inputs['reason']);
    $row->forceFill(['frozen_inputs' => $inputs])->save();

    expect(answerActivityCard($host, 'alice'))->toBeTrue();
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
    expect(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'revoked'])
        ->and($host->executions)->toBe([]);
});

it('prunes terminal inputs at thirty days and preserves accurately filtered receipts', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    $requester = $host->context;
    $rules = app(ResourceRules::class);
    app()->instance(RuleEvaluator::class, $rules);

    activityViewer($host, 'alice');
    $rules->setRules($requester->scope, new ResourceReference('item', 'personal'), 0, ['rules' => ['items' => Verdict::ALLOW]]);
    activityViewer($host, 'bob');
    $rules->setRules($requester->scope, new ResourceReference('item', 'shared'), 0, ['rules' => ['items' => Verdict::DELEGATE]]);
    $host->context = $requester;

    $authorize = app(Authorizer::class);
    $activity = app(Activity::class);
    $grants = app(GrantService::class);
    $reason = 'Private scheduling reason marker';
    $call = static function (string $id, bool $two = false) use ($host, $reason): Invocation {
        $proposal = $host->call($id, $two);

        return new Invocation($id, $proposal->toolName, $proposal->toolVersion, $proposal->arguments, $reason, $proposal->context, $proposal->executorId);
    };

    DB::enableQueryLog();
    $unused = $authorize->authorize($call('unused'))->grantToken();
    $revoked = $authorize->authorize($call('revoked'))->grantToken();
    $unknownCall = $call('unknown');
    $unknownGrant = $authorize->authorize($unknownCall)->grantToken();
    expect($unused)->toBeString()->and($revoked)->toBeString()->and($unknownGrant)->toBeString();
    if ($revoked === null || $unknownGrant === null || $unused === null) {
        throw new LogicException('Expected issued grants.');
    }

    expect($activity->revoke('revoked'))->toBeTrue()
        ->and(fn () => $grants->redeem($revoked, $call('revoked')))->toThrow(RedemptionRefused::class);
    $host->timeout = true;
    expect($grants->redeem($unknownGrant, $unknownCall)->status)->toBe(ExecutionStatus::UNKNOWN)
        ->and(array_column($host->executions, 'id'))->toBe(['unknown'])
        ->and($activity->recentUse('unknown'))->toBe([['status' => 'granted', 'outcome' => 'unknown', 'requester_id' => 'requester', 'approvals' => [], 'reason' => $reason]])
        ->and($activity->revoke('unknown'))->toBeFalse();
    $host->timeout = false;
    $grants->recordOutcome('unknown', ExecutionStatus::UNKNOWN, 'private provider reference');

    activityViewer($host, 'alice');
    $rules->setRules($requester->scope, new ResourceReference('item', 'personal'), 1, ['rules' => ['items' => Verdict::DELEGATE]]);
    $host->context = $requester;
    $authorize->authorize($call('waiting', two: true));
    $previews = DB::table('governance_approvals')->pluck('preview');
    $payloads = DB::table('governance_outbox_messages')->pluck('payload');
    expect($previews)->toHaveCount(2)->and($payloads)->toHaveCount(2);
    foreach ($previews->concat($payloads) as $cardContent) {
        expect($cardContent)->toBeString()
            ->not->toContain('Move the repair visit')
            ->not->toContain('10:00');
    }

    expect(DB::table('governance_invocations')->where('invocation_id', 'waiting')->value('frozen_inputs'))
        ->toBeString()
        ->not->toContain($reason)
        ->not->toContain('private argument marker')
        ->not->toContain('10:00');

    activityViewer($host, 'alice');
    $rules->setRules($requester->scope, new ResourceReference('item', 'personal'), 2, ['enabled' => false]);
    $host->context = $requester;
    $authorize->authorize($call('denied'));
    expect($activity->recentUse('unused'))->toBe([['status' => 'granted', 'requester_id' => 'requester', 'approvals' => [], 'reason' => $reason]]);

    $receipts = [];
    foreach (['alice', 'bob'] as $viewer) {
        activityViewer($host, $viewer);
        $receipts[] = $activity->recentUse();
    }
    $host->context = $requester;
    $requesterReceipts = $activity->recentUse();
    expect(array_column($requesterReceipts, 'reason'))->toBe(array_fill(0, 5, $reason));
    foreach ($requesterReceipts as $receipt) {
        unset($receipt['reason']);
        $receipts[] = $receipt;
    }

    $pruneOutput = [];
    $prune = static function () use (&$pruneOutput): int {
        $exitCode = Artisan::call('shared-tool-governance:prune');
        $pruneOutput[] = Artisan::output();

        return $exitCode;
    };

    $this->travel(29)->days();
    expect($prune())->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->whereNotNull('frozen_inputs')->count())->toBe(5);
    $this->travel(2)->days();

    expect($prune())->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->whereNotNull('frozen_inputs')->pluck('invocation_id')->all())->toBe(['unknown'])
        ->and(DB::table('governance_approvals')->whereNotNull('preview')->exists())->toBeFalse()
        ->and(DB::table('governance_outbox_messages')->whereNotNull('payload')->exists())->toBeFalse()
        ->and($activity->recentUse('unknown'))->toBe([['status' => 'granted', 'outcome' => 'unknown', 'requester_id' => 'requester', 'approvals' => [], 'reason' => $reason]]);

    $grants->recordOutcome('unknown', ExecutionStatus::SUCCEEDED, 'private provider reference');
    expect($prune())->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->whereNotNull('frozen_inputs')->count())->toBe(1);
    $this->travel(30)->days();
    expect($prune())->toBe(0, Artisan::output())
        ->and($prune())->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->whereNotNull('frozen_inputs')->count())->toBe(0);

    $receipts[] = $activity->recentUse();
    expect($activity->recentUse('unused'))->toBe([['status' => 'approved_unused', 'requester_id' => 'requester', 'approvals' => []]])
        ->and($activity->recentUse('revoked'))->toBe([['status' => 'revoked', 'requester_id' => 'requester', 'approvals' => []]])
        ->and($activity->recentUse('denied'))->toBe([['status' => 'denied', 'requester_id' => 'requester', 'approvals' => []]])
        ->and($activity->recentUse('unknown'))->toBe([['status' => 'granted', 'outcome' => 'success', 'requester_id' => 'requester', 'approvals' => []]])
        ->and($activity->recentUse('waiting'))->toBe([['status' => 'expired', 'requester_id' => 'requester', 'approvals' => []]]);

    activityViewer($host, 'alice');
    $receipts[] = $activity->recentUse();
    expect(array_column($activity->recentUse('denied')[0]['targets'] ?? [], 'id'))->toBe(['personal']);
    activityViewer($host, 'bob');
    $bob = $activity->recentUse();
    $receipts[] = $bob;
    expect(array_column($bob, 'invocation_id'))->toBe(['waiting'])
        ->and(array_column($bob[0]['targets'] ?? [], 'id'))->toBe(['shared']);
    expect(DB::table('governance_invocations')->where('invocation_id', 'waiting')->value('receipt_targets'))
        ->toBeString()
        ->not->toContain('personal')
        ->not->toContain('shared');

    $outputs = [
        'audit' => json_encode(DB::table('governance_audit_events')->get(), JSON_THROW_ON_ERROR),
        'receipts' => json_encode($receipts, JSON_THROW_ON_ERROR),
        'query log' => json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR),
        'prune output' => implode("\n", $pruneOutput),
    ];
    foreach ($outputs as $surface => $output) {
        foreach ([
            'unused grant token' => $unused,
            'revoked grant token' => $revoked,
            'redeemed grant token' => $unknownGrant,
            'raw argument value' => 'private argument marker',
            'raw reason' => $reason,
            'raw argument name' => 'scheduled_time',
            'private preview' => 'Move the repair visit',
            ...($surface === 'query log' ? [] : ['provider reference' => 'private provider reference']),
        ] as $field => $value) {
            expect(str_contains($output, $value))->toBeFalse("Found {$field} in {$surface}.");
        }
    }
});
