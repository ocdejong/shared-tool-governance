<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResumption;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

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

    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));
}

function decideFromInbox(ApprovalHost $host, string $human, ApprovalDecision $decision = ApprovalDecision::APPROVE): bool
{
    $scope = $host->context->scope;
    $card = $host->inbox->cards($scope, $human)[0];

    return app(Approvals::class)->decide($card->approvalId, $decision, new VerifiedReply($scope, $human, $card->cardId, "reply-$human"));
}

function resumeApproved(): void
{
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
}

it('delivers one private card per human and resumes only after all decisions', function (bool $two, bool $sameHuman): void {
    $host = new ApprovalHost;
    if ($sameHuman) {
        $host->householdApprover = 'alice';
    }

    requestApproval($host, $two);
    $scope = $host->context->scope;
    $alice = $host->inbox->cards($scope, 'alice');
    $bob = $host->inbox->cards($scope, 'bob');
    expect($alice)->toHaveCount(1)
        ->and($alice[0]->message)->toContain('Move the repair visit', 'personal', '09:00', '10:00', 'requester', 'Expires')
        ->not->toContain('hidden detail')
        ->and($host->inbox->cards($scope, 'requester'))->toBe([])
        ->and($bob)->toHaveCount($two && ! $sameHuman ? 1 : 0);
    foreach ([...$alice, ...$bob] as $card) {
        $labels = array_map(static fn (string $line) => strstr($line, ':', true), explode("\n", $card->message));
        expect($labels)->toBe(['Requester', 'Reason', 'Scope and change', 'Audience', 'Expires']);
    }
    if ($two && ! $sameHuman) {
        expect($alice[0]->message)->not->toContain('shared')
            ->and($bob[0]->message)->toContain('shared')->not->toContain('personal', 'hidden detail');
    }

    expect(decideFromInbox($host, 'alice'))->toBeTrue()
        ->and(decideFromInbox($host, 'alice'))->toBeTrue()
        ->and(decideFromInbox($host, 'alice', ApprovalDecision::DECLINE))->toBeFalse();
    if ($two && ! $sameHuman) {
        resumeApproved();
        expect($host->executions)->toBe([])
            ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval']);
        expect(decideFromInbox($host, 'bob'))->toBeTrue();
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
})->with([[false, false], [true, false], [true, true]]);

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

    expect(decideFromInbox($host, 'bob', ApprovalDecision::DECLINE))->toBeTrue();
    resumeApproved();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'declined']);
});

it('expires both unanswered cards and fully approved calls awaiting a worker', function (bool $approve): void {
    $host = new ApprovalHost;
    requestApproval($host);
    if ($approve) {
        expect(decideFromInbox($host, 'alice'))->toBeTrue();
    }

    $this->travel(30)->minutes();
    expect(decideFromInbox($host, 'alice', ApprovalDecision::DECLINE))->toBeFalse();
    app(ApprovalWork::class)->recover();
    resumeApproved();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'expired']);
})->with([false, true]);

it('rechecks current authority against the frozen call before execution', function (string $change): void {
    $host = new ApprovalHost;
    requestApproval($host, true);
    expect(decideFromInbox($host, 'alice'))->toBeTrue()
        ->and(decideFromInbox($host, 'bob'))->toBeTrue();

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
    resumeApproved();

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
    expect(decideFromInbox($host, 'alice'))->toBeTrue();
    $host->checksAfterApproval = 0;
    $host->interrupt = true;
    expect(resumeApproved(...))->toThrow(RuntimeException::class, 'Worker stopped after issuance.');

    $this->travel(6)->minutes();
    $host->interrupt = false;
    app(ApprovalWork::class)->recover();
    resumeApproved();
    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
});

it('leaves a grant unused when authority changes between its issuance and redemption', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    expect(decideFromInbox($host, 'alice'))->toBeTrue();
    $host->checksAfterApproval = 0;
    $host->denyAtRedemption = true;
    resumeApproved();

    expect($host->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'approved_unused']);
});

it('records an uncertain downstream result without retrying execution', function (): void {
    $host = new ApprovalHost;
    requestApproval($host);
    $host->timeout = true;
    expect(decideFromInbox($host, 'alice'))->toBeTrue();
    resumeApproved();
    resumeApproved();

    expect($host->executions)->toHaveCount(1)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'unknown']);
});

it('stages the request and its cards together and enqueues delivery after commit', function (): void {
    $host = new ApprovalHost;
    $host->bind();

    DB::beginTransaction();
    try {
        app(Authorizer::class)->authorize($host->call());
        Queue::assertNothingPushed();
    } finally {
        DB::rollBack();
    }

    app(ApprovalWork::class)->recover();
    Queue::assertNothingPushed();
    expect(app(Approvals::class)->receipt('visit'))->toBeNull();

    requestApproval($host);
    expect($host->inbox->cards($host->context->scope, 'alice'))->toHaveCount(1);
});

it('recovers undelivered work and retries delivery with the same private card ID', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    app(Authorizer::class)->authorize($host->call());

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
    expect(decideFromInbox($host, 'alice'))->toBeFalse();

    Queue::fake();
    app(ApprovalWork::class)->recover();
    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));

    expect($host->inbox->cards($host->context->scope, 'alice'))->toHaveCount(1)
        ->and(decideFromInbox($host, 'alice'))->toBeTrue();
    resumeApproved();
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
    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));
    expect(decideFromInbox($host, 'alice'))->toBeTrue();

    $host->context = new InvocationContext($scope, 'alice', 'runtime', ['alice']);
    $rules->setRules($scope, $personal, 1, ['enabled' => false]);
    $host->context = $original;
    expect(decideFromInbox($host, 'bob'))->toBeTrue();
    resumeApproved();

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
        ->and($invocation->getAttribute('frozen_inputs')['arguments_json'])->toContain('hidden detail');

    Queue::pushed(DeliverApproval::class)->sole()->handle(app(ApprovalDelivery::class));
    expect($message->refresh()->getRawOriginal('payload'))->toBeNull()
        ->and($approval->refresh()->getAttribute('preview'))->toContain('Move the repair visit');

    match ($status) {
        'granted' => expect(decideFromInbox($host, 'alice'))->toBeTrue(),
        'declined' => expect(decideFromInbox($host, 'alice', ApprovalDecision::DECLINE))->toBeTrue(),
        'expired' => $this->travel(30)->minutes(),
        default => throw new LogicException('Unknown receipt status.'),
    };
    app(ApprovalWork::class)->recover();
    resumeApproved();

    $receipt = $status === 'granted' ? ['status' => $status, 'outcome' => 'success'] : ['status' => $status];
    expect(app(Approvals::class)->receipt('visit'))->toBe($receipt)
        ->and($host->executions)->toHaveCount($status === 'granted' ? 1 : 0)
        ->and($approval->refresh()->getRawOriginal('preview'))->toBeNull()
        ->and($invocation->refresh()->getAttribute('frozen_inputs'))->not->toHaveKey('arguments_json')
        ->toHaveKeys(['targets', 'decisions', 'approval_requirements']);
})->with(['granted', 'declined', 'expired']);
