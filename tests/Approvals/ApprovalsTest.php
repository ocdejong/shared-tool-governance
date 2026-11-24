<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResumption;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalSetupError;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalUndeliverable;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryAccess;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryTarget;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceAuditEvent;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Rules\RuleAccessDenied;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake();
});

function requestApproval(ApprovalHost $host, bool $two = false): void
{
    $host->bind();
    $result = app(Authorizer::class)->authorize($host->call(two: $two));
    expect($result->jsonSerialize())->toBe(['status' => 'pending_approval', 'request_id' => 'visit'])
        ->and($result->grantToken())->toBeNull();

    ApprovalHost::deliverCards();
}

it('uses the application clock for approvals across time zones', function (string $appZone, ?string $sessionZone): void {
    $originalZone = date_default_timezone_get();
    config(['app.timezone' => $appZone]);
    date_default_timezone_set($appZone);
    $this->beforeApplicationDestroyed(static function () use ($originalZone): void {
        date_default_timezone_set($originalZone);
    });
    if ($sessionZone !== null) {
        DB::selectOne("select set_config('TimeZone', ?, false)", [$sessionZone]);
        $this->beforeApplicationDestroyed(static function (): void {
            DB::statement('reset time zone');
        });
    }

    $host = new ApprovalHost;
    $host->bind();
    $authorizer = app(Authorizer::class);
    $approvals = app(Approvals::class);

    $this->travelTo(now()->subMinutes(14)->startOfSecond());
    $created = now();
    $call = $host->call();
    $lateDelivery = $host->call('late-delivery');
    $authorizer->authorize($call);
    $authorizer->authorize($lateDelivery);
    $this->travelBack();

    Queue::pushed(DeliverApproval::class)->first()->handle(app(ApprovalDelivery::class));
    expect($host->inbox->cards($host->context->scope, 'alice'))->toHaveCount(1)
        ->and($host->decideFromInbox('alice'))->toBeTrue();
    ApprovalHost::runResumeJobs();
    expect($approvals->receipt($call->id))->toBe(['status' => 'granted', 'outcome' => 'success']);

    if ($sessionZone !== null) {
        expect((float) DB::table('governance_approvals')
            ->selectRaw('abs(extract(epoch from clock_timestamp() - decided_at)) as age')
            ->whereNotNull('decided_at')->value('age'))->toBeLessThan(5)
            ->and((float) DB::table('governance_invocations')
                ->selectRaw('abs(extract(epoch from clock_timestamp() - outcome_recorded_at)) as age')
                ->where('invocation_id', $call->id)->value('age'))->toBeLessThan(5);
    }

    $this->travelTo($created->copy()->addMinutes(15));
    expect($approvals->receipt($lateDelivery->id))->toBe(['status' => 'expired'])
        ->and($authorizer->authorize($lateDelivery)->status)->toBe('expired');
    ApprovalHost::deliverCards();
    expect($host->inbox->cards($host->context->scope, 'alice'))->toHaveCount(1);
    app(ApprovalWork::class)->recover();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount(1)
        ->and(DB::table('governance_invocations')->where('invocation_id', $lateDelivery->id)->value('status'))->toBe('expired');
})->with(getenv('DB_DATABASE') === false ? [
    'SQLite with New York app' => ['America/New_York', null],
    'SQLite with Tokyo app' => ['Asia/Tokyo', null],
] : [
    'New York app and session' => ['America/New_York', 'America/New_York'],
    'Tokyo app and session' => ['Asia/Tokyo', 'Asia/Tokyo'],
    'UTC app and Etc/UTC session' => ['UTC', 'Etc/UTC'],
    'UTC app and New York session' => ['UTC', 'America/New_York'],
    'UTC app and Tokyo session' => ['UTC', 'Asia/Tokyo'],
    'New York app and Tokyo session' => ['America/New_York', 'Asia/Tokyo'],
]);

it('delivers one private card per requirement and resumes only after all decisions', function (bool $two): void {
    $host = new ApprovalHost;

    requestApproval($host, $two);
    $scope = $host->context->scope;
    $alice = $host->inbox->cards($scope, 'alice');
    $bob = $host->inbox->cards($scope, 'bob');
    expect($alice)->toHaveCount(1)
        ->and($alice[0]->message)->toContain('Move the repair visit', 'personal', '09:00', '10:00', 'requester', 'Expires')
        ->and($host->inbox->cards($scope, 'requester'))->toBe([])
        ->and($bob)->toHaveCount($two ? 1 : 0);
    foreach ([...$alice, ...$bob] as $card) {
        $labels = array_map(static fn (string $line) => strstr($line, ':', true), explode("\n", $card->message));
        expect($labels)->toBe(['Requester', 'Reason', 'Scope and change', 'Audience', 'Expires'])
            ->and($card->message)->not->toContain('private reason marker')
            ->and($card->message)->not->toContain('private argument marker');
    }
    if ($two) {
        expect($alice[0]->message)->not->toContain('shared')
            ->and($bob[0]->message)->toContain('shared')
            ->and($bob[0]->message)->not->toContain('personal');
    }

    expect($host->decideFromInbox('alice'))->toBeTrue()
        ->and($host->decideFromInbox('alice'))->toBeTrue()
        ->and($host->decideFromInbox('alice', ApprovalDecision::DECLINE))->toBeFalse();
    if ($two) {
        ApprovalHost::runResumeJobs();
        expect($host->executions)->toBe([])
            ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval']);
        expect($host->decideFromInbox('bob'))->toBeTrue();
    }

    Queue::fake(); // Simulate loss of the queued wakeup and rebuild work from committed state.
    $worker = new ApprovalHost($host->inbox);
    $worker->householdApprover = $host->householdApprover;
    $worker->workerOnly = true;
    $worker->bind();
    app(ApprovalWork::class)->recover();
    Queue::pushed(ResumeApproval::class)->each(static function (ResumeApproval $job): void {
        $restartedJob = unserialize(serialize($job), ['allowed_classes' => [ResumeApproval::class]]);
        if (! $restartedJob instanceof ResumeApproval) {
            throw new LogicException('The worker could not reload the job.');
        }
        $restartedJob->handle(app(ApprovalResumption::class));
        $restartedJob->handle(app(ApprovalResumption::class));
    });

    $worker->workerOnly = false;
    expect($worker->executions)->toHaveCount(1)
        ->and($worker->executions[0]->arguments)->toBe($host->call(two: $two)->arguments + ['timezone' => 'Europe/Amsterdam'])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success']);
    $worker->context = new InvocationContext($scope, 'alice', 'runtime', ['alice']);
    expect(app(Approvals::class)->receipt('visit'))->toBeNull();
})->with([false, true]);

it('binds a decision to the verified scope, sender and original card', function (): void {
    $host = new ApprovalHost;
    $host->context = new InvocationContext(new Scope('01', 'home'), 'requester', 'runtime', ['requester']);
    requestApproval($host, true);
    $scope = $host->context->scope;
    $alice = $host->inbox->cards($scope, 'alice')[0];
    $bob = $host->inbox->cards($scope, 'bob')[0];

    foreach ([
        new VerifiedReply($scope, 'bob', $alice->cardId, 'reply-1'),
        new VerifiedReply($scope, 'alice', $bob->cardId, 'reply-2'),
        new VerifiedReply(new Scope('1', 'home'), 'alice', $alice->cardId, 'reply-3'),
    ] as $reply) {
        expect(app(Approvals::class)->decide($alice->approvalId, ApprovalDecision::APPROVE, $reply))->toBeFalse();
    }

    expect($host->decideFromInbox('bob', ApprovalDecision::DECLINE))->toBeTrue();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'declined']);
});

it('isolates rules, discovery, approvals and receipts for identical IDs in two workspaces', function (): void {
    $host = new ApprovalHost;
    $host->allHouseholdOwned = true;
    $host->bind();
    app()->instance(RuleEvaluator::class, app(ResourceRules::class));
    $authorizer = app(Authorizer::class);
    $approvals = app(Approvals::class);
    $rules = app(ResourceRules::class);
    $activity = app(Activity::class);
    $first = new Scope(hostScope: 'tenant', workspaceId: 'team-a');
    $second = new Scope(hostScope: 'tenant', workspaceId: 'team-b');
    $resource = new ResourceReference('item', 'personal', 'r1');

    $host->context = new InvocationContext($first, 'bob', 'runtime', ['bob']);
    $rules->setRules($first, $resource, 0, ['rules' => ['items' => Verdict::DELEGATE]]);
    $host->context = new InvocationContext($second, 'bob', 'runtime', ['bob']);
    expect($rules->getRules($second, $resource)->revision)->toBe(0);
    $rules->setRules($second, $resource, 0, ['rules' => ['items' => Verdict::DENY]]);

    $discover = new ToolDiscovery($host, $host, $rules, app(ToolRegistry::class), static fn () => DiscoveryAccess::VISIBLE);
    $target = new DiscoveryTarget(new OwnerReference(OwnerType::WORKSPACE, $second->workspaceId), $resource);
    expect($discover->discover($second, [$target])->actions['items.reschedule'][0]->verdict)->toBe(Verdict::DENY)
        ->and(fn () => $discover->discover($first, [$target]))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $discover->discover($first, []))->toThrow(RuleAccessDenied::class);

    $host->context = new InvocationContext($first, 'requester', 'runtime', ['requester']);
    expect($authorizer->authorize($host->call())->status)->toBe('pending_approval');
    ApprovalHost::deliverCards();
    $card = $host->inbox->cards($first, 'bob')[0];

    $host->context = new InvocationContext($second, 'requester', 'runtime', ['requester']);
    expect($approvals->receipt('visit'))->toBeNull()
        ->and($activity->recentUse('visit'))->toBe([])
        ->and($authorizer->authorize($host->call())->status)->toBe('denied')
        ->and($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, new VerifiedReply($second, 'bob', $card->cardId, 'reply')))->toBeFalse()
        ->and($approvals->receipt('visit'))->toBe(['status' => 'denied'])
        ->and($activity->recentUse('visit')[0]['status'])->toBe('denied');

    $host->context = new InvocationContext($first, 'requester', 'runtime', ['requester']);
    expect($host->decideFromInbox('bob'))->toBeTrue();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount(1)
        ->and($host->executions[0]->context->scope)->toEqual($first)
        ->and($approvals->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success'])
        ->and($activity->recentUse('visit')[0]['status'])->toBe('granted')
        ->and(DB::table('governance_resource_bindings')->pluck('owner_type')->all())->toBe(['household', 'household'])
        ->and(DB::table('governance_approvals')->value('owner_type'))->toBe('household');
});

it('refuses a reply and receipt when the relevant members leave the workspace', function (): void {
    $host = new ApprovalHost;
    $host->context = new InvocationContext($host->context->scope, 'requester', 'runtime', ['requester', 'bob']);
    requestApproval($host);
    $host->members = ['requester', 'alice'];
    $discovery = new ToolDiscovery($host, $host, $host, [], static fn () => throw new LogicException('Check membership before visibility.'));

    expect(fn () => $discovery->discover($host->context->scope, []))->toThrow(RuleAccessDenied::class)
        ->and($host->decideFromInbox('alice'))->toBeFalse()
        ->and(app(Approvals::class)->receipt('visit'))->toBeNull()
        ->and(app(Activity::class)->recentUse('visit'))->toBe([])
        ->and(app(Authorizer::class)->authorize($host->call())->status)->toBe('denied')
        ->and(GovernanceApproval::query()->firstOrFail()->getAttribute('decision'))->toBeNull();
    Queue::assertNotPushed(ResumeApproval::class);
});

it('expires both unanswered cards and fully approved calls awaiting a worker', function (bool $approve): void {
    $host = new ApprovalHost;
    requestApproval($host);
    if ($approve) {
        expect($host->decideFromInbox('alice'))->toBeTrue();
    }

    $this->travel(30)->minutes();
    expect($host->decideFromInbox('alice', ApprovalDecision::DECLINE))->toBeFalse();
    app(ApprovalWork::class)->recover();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'expired']);
})->with([false, true]);

it('rechecks current authority against the frozen call before execution', function (string $change): void {
    $host = new ApprovalHost;
    requestApproval($host, true);
    expect($host->decideFromInbox('alice'))->toBeTrue()
        ->and($host->decideFromInbox('bob'))->toBeTrue();

    $original = $host->context;
    match ($change) {
        'target revision' => $host->revision = 'r3',
        'scope' => $host->context = new InvocationContext(new Scope('tenant', 'other-home'), 'requester', 'runtime', ['requester']),
        'requester' => $host->context = new InvocationContext($original->scope, 'alice', 'runtime', $original->audience),
        'runtime' => $host->context = new InvocationContext($original->scope, 'requester', 'new-runtime', $original->audience),
        'executor' => $host->bind('new-worker'),
        'audience' => $host->context = new InvocationContext($host->context->scope, 'requester', 'runtime', ['requester', 'bob']),
        'owner' => $host->personalOwner = 'bob',
        'approver' => $host->householdApprover = 'alice',
        'revocation' => $host->deny = true,
        'membership' => $host->members = ['requester', 'bob'],
        default => throw new LogicException('Unknown fixture change.'),
    };
    $host->workerOnly = true;
    ApprovalHost::runResumeJobs();

    $host->workerOnly = false;
    if (in_array($change, ['scope', 'requester', 'runtime'], true)) {
        expect(app(Approvals::class)->receipt('visit'))->toBeNull();
    }
    $host->context = $original;

    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'revoked']);
})->with(['target revision', 'scope', 'requester', 'runtime', 'executor', 'audience', 'owner', 'approver', 'revocation', 'membership']);

it('reports an approved grant that expired unused after the worker stopped', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect($host->decideFromInbox('alice'))->toBeTrue();
    $host->checksAfterApproval = 0;
    $host->interrupt = true;
    expect(ApprovalHost::runResumeJobs(...))->toThrow(RuntimeException::class, 'Worker stopped after issuance.');

    DB::table('governance_grants')->update(['expires_at' => now()->subDay()]);
    $this->travel(-3)->days();
    $host->interrupt = false;
    app(ApprovalWork::class)->recover();
    Queue::assertPushed(ResumeApproval::class, 2);
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
});

it('leaves a grant unused when authority changes between its issuance and redemption', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect($host->decideFromInbox('alice'))->toBeTrue();
    $host->checksAfterApproval = 0;
    $host->denyAtRedemption = true;
    ApprovalHost::runResumeJobs();

    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
});

it('records an uncertain downstream result without retrying execution', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    $host->timeout = true;
    expect($host->decideFromInbox('alice'))->toBeTrue();
    ApprovalHost::runResumeJobs();
    ApprovalHost::runResumeJobs();

    expect($host->executions)->toHaveCount(1)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'unknown']);
});

it('reports an unreachable owner after commit while the request keeps its expiry', function (
    ?string $householdApprover,
    array $approversByResource,
    bool $allHouseholdOwned,
): void {
    $this->freezeSecond();
    $host = new ApprovalHost;
    $host->householdApprover = $householdApprover;
    $host->approversByResource = $approversByResource;
    $host->allHouseholdOwned = $allHouseholdOwned;
    $host->bind();
    $authorizer = app(Authorizer::class);
    $call = $host->call(two: true);
    $reported = [];
    Event::listen(ApprovalUndeliverable::class, function (ApprovalUndeliverable $event) use (&$reported): void {
        expect(DB::transactionLevel())->toBe(0);
        $reported[] = $event;
    });

    DB::beginTransaction();
    try {
        $result = $authorizer->authorize($call);
        expect($result->jsonSerialize())->toBe(['status' => 'pending_approval', 'request_id' => 'visit'])
            ->and($result->grantToken())->toBeNull()
            ->and(GovernanceApproval::query()->count())->toBe(0)
            ->and(GovernanceOutboxMessage::query()->count())->toBe(0)
            ->and(GovernanceGrant::query()->count())->toBe(0)
            ->and($reported)->toBe([]);
        Queue::assertNothingPushed();
    } finally {
        DB::rollBack();
    }
    expect($reported)->toBe([])
        ->and(GovernanceAuditEvent::query()->count())->toBe(0);

    DB::transaction(function () use ($authorizer, $call, &$reported): void {
        $authorizer->authorize($call);
        expect($reported)->toBe([]);
    });
    $authorizer->authorize($call);

    expect($reported)->toHaveCount(1)
        ->and($reported[0]->invocationId)->toBe($call->id)
        ->and($reported[0]->scope)->toEqual($host->context->scope);
    $audit = GovernanceAuditEvent::query()->where('event', 'approval_undeliverable')->sole();
    expect($audit->getAttribute('invocation_row_id'))->toBe(GovernanceInvocation::query()->sole()->getKey())
        ->and($audit->getAttribute('details'))->toBe([]);
    Queue::assertNothingPushed();

    $this->travel(14)->minutes();
    expect(app(Approvals::class)->receipt($call->id))->toBe(['status' => 'pending_approval']);
    $this->travel(1)->minutes();
    expect(app(Approvals::class)->receipt($call->id))->toBe(['status' => 'expired']);
    app(ApprovalWork::class)->recover();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([]);
})->with([
    'no approver' => [null, [], false],
    'empty approver' => ['', [], false],
    'approver outside the scope' => ['stranger', [], false],
    'approver is not the personal owner' => ['bob', ['personal' => 'bob'], false],
    'same person for two owners' => ['alice', [], false],
    'conflicting approvers for one owner' => ['bob', ['personal' => 'alice'], true],
]);

it('rolls back delegated calls whose tool cannot provide every approval preview', function (?Closure $preview): void {
    Event::fake([ApprovalUndeliverable::class]);
    $host = new ApprovalHost;
    $validPreview = $host->approvalPreview;
    $host->approvalPreview = $preview;
    $host->bind();
    $call = $host->call(two: true);

    expect(fn () => app(Authorizer::class)->authorize($call))->toThrow(
        fn (ApprovalSetupError $error) => expect($error->invocationId)->toBe($call->id),
    );

    expect(app(Approvals::class)->receipt($call->id))->toBeNull()
        ->and(GovernanceInvocation::query()->count())->toBe(0)
        ->and(GovernanceAuditEvent::query()->count())->toBe(0)
        ->and(GovernanceApproval::query()->count())->toBe(0)
        ->and(GovernanceOutboxMessage::query()->count())->toBe(0)
        ->and(GovernanceGrant::query()->count())->toBe(0)
        ->and($host->executions)->toBe([]);
    Queue::assertNothingPushed();
    Event::assertNotDispatched(ApprovalUndeliverable::class);

    $host->approvalPreview = $validPreview;
    $host->bind();
    expect(app(Authorizer::class)->authorize($call)->status)->toBe('pending_approval');
    Queue::assertPushed(DeliverApproval::class, 2);
})->with([
    'missing callback' => [null],
    'null preview for the second approver' => [static fn (Invocation $call, array $targets, string $approver): ?ApprovalPreview => $approver === 'alice'
        ? new ApprovalPreview('Valid reason', 'Valid change')
        : null],
    'blank preview reason' => [static fn () => new ApprovalPreview("\u{00A0}\t", 'Valid change')],
    'blank preview scope and change' => [static fn () => new ApprovalPreview('Valid reason', " \n")],
]);

it('stages the request and all its cards together and enqueues delivery after commit', function (): void {
    $host = new ApprovalHost;
    $host->bind();

    DB::beginTransaction();
    try {
        app(Authorizer::class)->authorize($host->call(two: true));
        expect(GovernanceInvocation::query()->count())->toBe(1)
            ->and(GovernanceApproval::query()->count())->toBe(2)
            ->and(GovernanceOutboxMessage::query()->count())->toBe(2)
            ->and(GovernanceGrant::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    } finally {
        DB::rollBack();
    }

    app(ApprovalWork::class)->recover();
    Queue::assertNothingPushed();
    expect(app(Approvals::class)->receipt('visit'))->toBeNull()
        ->and(GovernanceApproval::query()->count())->toBe(0)
        ->and(GovernanceOutboxMessage::query()->count())->toBe(0);

    requestApproval($host, two: true);
    expect($host->inbox->cards($host->context->scope, 'alice'))->toHaveCount(1)
        ->and($host->inbox->cards($host->context->scope, 'bob'))->toHaveCount(1)
        ->and($host->executions)->toBe([]);
});

it('recovers undelivered work and retries delivery with the same private card ID', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    app(Authorizer::class)->authorize($host->call());
    $deliveryId = GovernanceOutboxMessage::query()->sole()->getAttribute('delivery_id');

    $flaky = new class($host->inbox) implements ApprovalNotifier
    {
        private bool $interrupted = false;

        public function __construct(private readonly LocalInbox $inbox) {}

        public function deliver(Scope $scope, string $deliveryId, string $recipientId, string $message): string
        {
            $card = $this->inbox->deliver($scope, $deliveryId, $recipientId, $message);
            if (! $this->interrupted) {
                $this->interrupted = true;

                throw new RuntimeException('Delivery confirmation was lost.');
            }

            return $card;
        }
    };
    app()->instance(ApprovalNotifier::class, $flaky);
    $job = Queue::pushed(DeliverApproval::class)->sole();
    expect(fn () => $job->handle(app(ApprovalDelivery::class)))->toThrow(RuntimeException::class, 'Delivery confirmation was lost.');
    expect($host->decideFromInbox('alice'))->toBeFalse()
        ->and($host->executions)->toBe([]);

    Queue::fake();
    app(ApprovalWork::class)->recover();
    ApprovalHost::deliverCards();

    $cards = $host->inbox->cards($host->context->scope, 'alice');
    expect($cards)->toHaveCount(1)
        ->and($cards[0]->approvalId)->toBe($deliveryId)
        ->and($host->decideFromInbox('alice'))->toBeTrue();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount(1);
});

it('honors an owner turning off access while another approval is outstanding', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    $rules = app(ResourceRules::class);
    app()->instance(RuleEvaluator::class, $rules);
    $original = $host->context;
    $scope = $original->scope;
    $personal = new ResourceReference('item', 'personal');
    $shared = new ResourceReference('item', 'shared');
    $delegation = ['rules' => ['items' => Verdict::DELEGATE]];

    $host->context = new InvocationContext($scope, 'alice', 'runtime', ['alice']);
    $rules->setRules($scope, $personal, 0, $delegation);
    $host->context = new InvocationContext($scope, 'bob', 'runtime', ['bob']);
    $rules->setRules($scope, $shared, 0, $delegation);
    $host->context = $original;
    expect(app(Authorizer::class)->authorize($host->call(two: true))->status)->toBe('pending_approval');
    ApprovalHost::deliverCards();
    expect($host->decideFromInbox('alice'))->toBeTrue();

    $host->context = new InvocationContext($scope, 'alice', 'runtime', ['alice']);
    $rules->setRules($scope, $personal, 1, ['enabled' => false]);
    $host->context = $original;
    expect($host->decideFromInbox('bob'))->toBeTrue();
    ApprovalHost::runResumeJobs();

    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'revoked']);
});

it('removes private card content and execution arguments after use', function (string $status): void {
    $host = new ApprovalHost;
    $host->bind();
    expect(app(Authorizer::class)->authorize($host->call())->status)->toBe('pending_approval');

    $message = GovernanceOutboxMessage::query()->sole();
    $approval = GovernanceApproval::query()->sole();
    $invocation = GovernanceInvocation::query()->sole();
    expect($message->getAttribute('payload'))->toContain('Move the repair visit')
        ->and($approval->getAttribute('preview'))->toContain('Move the repair visit')
        ->and($invocation->getAttribute('frozen_inputs')['arguments_json'])->toContain('private argument marker');

    Queue::pushed(DeliverApproval::class)->sole()->handle(app(ApprovalDelivery::class));
    expect($message->refresh()->getRawOriginal('payload'))->toBeNull()
        ->and($approval->refresh()->getAttribute('preview'))->toContain('Move the repair visit');

    match ($status) {
        'granted' => expect($host->decideFromInbox('alice'))->toBeTrue(),
        'declined' => expect($host->decideFromInbox('alice', ApprovalDecision::DECLINE))->toBeTrue(),
        'expired' => $this->travel(30)->minutes(),
        default => throw new LogicException('Unknown receipt status.'),
    };
    app(ApprovalWork::class)->recover();
    ApprovalHost::runResumeJobs();

    $receipt = $status === 'granted' ? ['status' => $status, 'outcome' => 'success'] : ['status' => $status];
    expect(app(Approvals::class)->receipt('visit'))->toBe($receipt)
        ->and($host->executions)->toHaveCount($status === 'granted' ? 1 : 0)
        ->and($approval->refresh()->getRawOriginal('preview'))->toBeNull()
        ->and($invocation->refresh()->getAttribute('frozen_inputs'))->not->toHaveKey('arguments_json')
        ->toHaveKeys(['targets', 'decisions', 'approval_requirements']);
})->with(['granted', 'declined', 'expired']);

it('resumes with the registered executor even when the proposal names a different binding', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    $this->mock(ToolExecutor::class)->shouldNotReceive('execute');
    app()->instance('caller-worker', app(ToolExecutor::class));

    expect(app(Authorizer::class)->authorize($host->call(executor: 'caller-worker'))->status)->toBe('pending_approval');
    ApprovalHost::deliverCards();
    expect($host->decideFromInbox('alice'))->toBeTrue();
    ApprovalHost::runResumeJobs();

    expect($host->executions)->toHaveCount(1)
        ->and($host->executions[0]->executorId)->toBe('worker');
});

it('refuses a resumed grant when ownership changes after issuance', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect($host->decideFromInbox('alice'))->toBeTrue();
    $host->checksAfterApproval = 0;
    $host->ownerAtRedemption = 'bob';

    ApprovalHost::runResumeJobs();

    expect($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->sole()->getAttribute('redeemed_at'))->toBeNull()
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
});

it('refuses mismatched grant tokens when resuming approved work', function (string $source): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect($host->decideFromInbox('alice'))->toBeTrue();
    $host->checksAfterApproval = 0;

    $original = $host->context;
    $otherRowId = null;
    if ($source !== 'wrong token') {
        if ($source === 'another household') {
            $host->context = new InvocationContext(new Scope('tenant', 'other-home'), 'requester', 'runtime', ['requester']);
        }

        $other = $host->call(id: $source === 'another household' ? 'visit' : 'other-visit');
        app(Authorizer::class)->authorize($other);
        $otherRowId = GovernanceInvocation::query()->latest('id')->value('id');
        $host->context = $original;
    }

    $transactions = 0;
    DB::connection()->beforeStartingTransaction(static function () use ($otherRowId, &$transactions): void {
        if (++$transactions !== 2) {
            return;
        }

        $issued = DB::table('governance_grants')->sole();
        if ($otherRowId === null) {
            DB::table('governance_grants')->where('id', $issued->id)
                ->update(['token_hash' => str_repeat('0', 64)]);

            return;
        }

        DB::table('governance_grants')->where('id', $issued->id)->update(['invocation_row_id' => $otherRowId]);
        DB::table('governance_grants')->insert([
            'invocation_row_id' => $issued->invocation_row_id,
            'token_hash' => str_repeat('0', 64),
            'expires_at' => $issued->expires_at,
        ]);
    });

    ApprovalHost::runResumeJobs();

    expect($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->whereNotNull('redeemed_at')->count())->toBe(0)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
})->with(['wrong token', 'another invocation', 'another household']);

it('keeps approved work retryable when the executor cannot be resolved', function (bool $bound): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect($host->decideFromInbox('alice'))->toBeTrue();
    app()->forgetInstance('worker');
    if ($bound) {
        app()->instance('worker', new stdClass);
    }

    expect(ApprovalHost::runResumeJobs(...))->toThrow($bound ? InvalidArgumentException::class : BindingResolutionException::class)
        ->and($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->count())->toBe(0)
        ->and(GovernanceInvocation::query()->sole()->getAttribute('outcome'))->toBeNull()
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved']);

    app()->instance('worker', $host);
    Queue::fake();
    app(ApprovalWork::class)->recover();
    Queue::assertPushed(ResumeApproval::class, 1);
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount(1)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success']);
})->with(['wrong type' => true, 'missing binding' => false]);
