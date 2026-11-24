<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResponse;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalUndeliverable;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\ApproverPolicy;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\ApprovalRule;
use Ocdejong\SharedToolGovernance\Data\GroupApprovalPrompt;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalCard;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Models\GovernanceOutboxMessage;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        GovernanceApproval::query()->delete();
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake();
});

function groupHost(ApprovalRule $rule, bool $withPersonal = false, ?ApprovalRule $personalRule = null): ApprovalHost
{
    $host = new ApprovalHost;
    $host->allHouseholdOwned = ! $withPersonal;
    $host->bind();
    app()->instance(ApproverPolicy::class, new class($rule, $personalRule) implements ApproverPolicy
    {
        public function __construct(private ApprovalRule $rule, private ?ApprovalRule $personalRule) {}

        public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?ApprovalRule
        {
            return $owner->id === $scope->workspaceId ? $this->rule : $this->personalRule;
        }
    });

    return $host;
}

function groupReply(LocalCard $card, ApprovalHost $host, string $person, ApprovalDecision $decision): ApprovalResponse
{
    return app(Approvals::class)->respond(
        $card->approvalId,
        $decision,
        new VerifiedReply($host->context->scope, $person, $card->cardId, 'reply-'.$card->cardId),
    );
}

it('settles an any requirement with the first eligible reply and returns the recorded decision to later responders', function (
    string $first, string $second, ApprovalDecision $decision,
): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['bob', 'alice', 'alice']));
    app(Authorizer::class)->authorize($host->call());
    ApprovalHost::deliverCards();
    $scope = $host->context->scope;
    $firstCard = $host->inbox->cards($scope, $first)[0];
    $secondCard = $host->inbox->cards($scope, $second)[0];

    expect(GovernanceApproval::query()->count())->toBe(2)
        ->and(groupReply($firstCard, $host, 'requester', ApprovalDecision::APPROVE)->status)->toBe('refused')
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval']);

    $winner = groupReply($firstCard, $host, $first, $decision);
    $later = groupReply($secondCard, $host, $second, $decision === ApprovalDecision::APPROVE ? ApprovalDecision::DECLINE : ApprovalDecision::APPROVE);
    expect($winner->status)->toBe('settled')->and($winner->decision)->toBe($decision)->and($winner->accepted)->toBeTrue()
        ->and($later->status)->toBe('settled')->and($later->decision)->toBe($decision)->and($later->accepted)->toBeFalse()
        ->and(groupReply($firstCard, $host, $first, $decision)->accepted)->toBeTrue()
        ->and(GovernanceApproval::query()->whereNotNull('decision')->count())->toBe(1);
    ApprovalHost::runResumeJobs();
    ApprovalHost::runResumeJobs();

    $approved = $decision === ApprovalDecision::APPROVE;
    expect($host->executions)->toHaveCount((int) $approved)
        ->and(GovernanceGrant::query()->count())->toBe((int) $approved)
        ->and(app(Approvals::class)->receipt('visit'))->toBe($approved ? ['status' => 'granted', 'outcome' => 'success'] : ['status' => 'declined']);
})->with([
    ['alice', 'bob', ApprovalDecision::APPROVE],
    ['bob', 'alice', ApprovalDecision::APPROVE],
    ['bob', 'alice', ApprovalDecision::DECLINE],
    ['alice', 'bob', ApprovalDecision::DECLINE],
]);

it('records an all vote until every eligible person approves or one declines', function (ApprovalDecision $decision): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ALL, ['bob', 'alice']));
    app(Authorizer::class)->authorize($host->call());
    ApprovalHost::deliverCards();
    $scope = $host->context->scope;
    $alice = $host->inbox->cards($scope, 'alice')[0];
    $bob = $host->inbox->cards($scope, 'bob')[0];

    $first = groupReply($alice, $host, 'alice', ApprovalDecision::APPROVE);
    expect($first->status)->toBe('recorded')->and($first->decision)->toBeNull()
        ->and(groupReply($alice, $host, 'alice', ApprovalDecision::APPROVE)->status)->toBe('recorded');
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->count())->toBe(0)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval']);

    $last = groupReply($bob, $host, 'bob', $decision);
    expect($last->status)->toBe('settled')->and($last->decision)->toBe($decision);
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount((int) ($decision === ApprovalDecision::APPROVE));
})->with(ApprovalDecision::cases());

it('stages only viable distinct cards for forced assignments and accepts replies in either order', function (bool $groupFirst, ApprovalDecision $firstDecision): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['alice', 'bob']), withPersonal: true);
    app(Authorizer::class)->authorize($host->call(two: true));
    ApprovalHost::deliverCards();
    $scope = $host->context->scope;
    $alice = $host->inbox->cards($scope, 'alice');
    $bob = $host->inbox->cards($scope, 'bob');
    expect($alice)->toHaveCount(1)->and($bob)->toHaveCount(1)
        ->and($alice[0]->message)->toContain('personal')->not->toContain('shared')
        ->and($bob[0]->message)->toContain('shared')->not->toContain('personal');
    $requirements = GovernanceInvocation::query()->sole()->getAttribute('frozen_inputs')['approval_requirements'];
    expect($requirements[1]['eligible_people'])->toBe(['alice', 'bob'])
        ->and($requirements[1]['effective_people'])->toBe(['bob']);

    [$first, $second] = $groupFirst ? [$bob[0], $alice[0]] : [$alice[0], $bob[0]];
    [$firstPerson, $secondPerson] = $groupFirst ? ['bob', 'alice'] : ['alice', 'bob'];
    expect(groupReply($bob[0], $host, 'alice', ApprovalDecision::APPROVE)->status)->toBe('refused');
    $reply = groupReply($first, $host, $firstPerson, $firstDecision);
    expect($reply->status)->toBe('settled')->and($reply->accepted)->toBeTrue()->and($reply->decision)->toBe($firstDecision)
        ->and(GovernanceApproval::query()->whereNotNull('decision')->count())->toBe(1)
        ->and(GovernanceGrant::query()->count())->toBe(0);

    $approved = $firstDecision === ApprovalDecision::APPROVE;
    expect(app(Approvals::class)->receipt('visit'))->toBe(['status' => $approved ? 'pending_approval' : 'declined'])
        ->and(groupReply($second, $host, $secondPerson, ApprovalDecision::APPROVE)->accepted)->toBe($approved);
    $late = groupReply($first, $host, $firstPerson, $approved ? ApprovalDecision::DECLINE : ApprovalDecision::APPROVE);
    expect($late->status)->toBe('settled')->and($late->accepted)->toBeFalse()->and($late->decision)->toBe($firstDecision);

    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount((int) $approved)
        ->and(GovernanceGrant::query()->count())->toBe((int) $approved);
})->with([false, true])->with(ApprovalDecision::cases());

it('records an eligible decision that exhausts an ambiguous assignment and closes the call', function (ApprovalDecision $decision): void {
    Event::fake([ApprovalUndeliverable::class]);
    $host = new ApprovalHost;
    $host->includeThirdOwner = true;
    $host->members[] = 'carol';
    $host->bind();
    app()->instance(ApproverPolicy::class, new class implements ApproverPolicy
    {
        public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ApprovalRule
        {
            $people = match ($resource->id) {
                'personal' => ['alice', 'bob'],
                'shared' => ['bob', 'carol'],
                'third' => ['alice', 'carol'],
                default => throw new LogicException('Unexpected target'),
            };

            return new ApprovalRule(ApprovalMode::ANY, $people);
        }
    });
    app(Authorizer::class)->authorize($host->call(two: true));
    ApprovalHost::deliverCards();
    $scope = $host->context->scope;
    [$alicePersonal, $aliceThird] = $host->inbox->cards($scope, 'alice');
    [, $bobShared] = $host->inbox->cards($scope, 'bob');
    [$carolShared, $carolThird] = $host->inbox->cards($scope, 'carol');

    expect(groupReply($alicePersonal, $host, 'alice', ApprovalDecision::APPROVE)->accepted)->toBeTrue()
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval']);
    $accepted = DB::transaction(function () use ($carolShared, $host, $decision): ApprovalResponse {
        $reply = groupReply($carolShared, $host, 'carol', $decision);
        Event::assertNotDispatched(ApprovalUndeliverable::class);

        return $reply;
    });
    $unreachable = $decision === ApprovalDecision::APPROVE;
    expect($accepted->status)->toBe('settled')->and($accepted->decision)->toBe($decision)->and($accepted->accepted)->toBeTrue()
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => $unreachable ? 'approval_undeliverable' : 'declined']);
    $late = groupReply($bobShared, $host, 'bob', ApprovalDecision::APPROVE);
    expect($late->status)->toBe('settled')->and($late->decision)->toBe($decision)->and($late->accepted)->toBeFalse()
        ->and(groupReply($aliceThird, $host, 'alice', ApprovalDecision::APPROVE)->status)->toBe('refused')
        ->and(groupReply($carolThird, $host, 'carol', ApprovalDecision::APPROVE)->status)->toBe('refused');

    if ($unreachable) {
        Event::assertDispatched(ApprovalUndeliverable::class, fn (ApprovalUndeliverable $event): bool => $event->scope->equals($scope) && $event->invocationId === 'visit' && $event->reason === 'distinct_completion_impossible');
        expect(groupReply($carolShared, $host, 'carol', $decision)->accepted)->toBeTrue()
            ->and(app(Authorizer::class)->authorize($host->call(two: true))->status)->toBe('approval_undeliverable');
        Event::assertDispatchedTimes(ApprovalUndeliverable::class, 1);
        Queue::assertNotPushed(ResumeApproval::class);
    } else {
        Event::assertNotDispatched(ApprovalUndeliverable::class);
    }

    ApprovalHost::runResumeJobs();
    expect($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->count())->toBe(0)
        ->and(GovernanceApproval::query()->whereNotNull('decision')->count())->toBe(2)
        ->and(GovernanceApproval::query()->whereNotNull('preview')->count())->toBe(0);
})->with(ApprovalDecision::cases());

it('requires two different people when the host explicitly gives two owners overlapping any groups', function (): void {
    $rule = new ApprovalRule(ApprovalMode::ANY, ['alice', 'bob']);
    $host = groupHost($rule, withPersonal: true, personalRule: $rule);
    app(Authorizer::class)->authorize($host->call(two: true));
    ApprovalHost::deliverCards();

    $scope = $host->context->scope;
    [$alicePersonal, $aliceWorkspace] = $host->inbox->cards($scope, 'alice');
    [, $bobWorkspace] = $host->inbox->cards($scope, 'bob');
    expect(groupReply($alicePersonal, $host, 'alice', ApprovalDecision::APPROVE)->accepted)->toBeTrue()
        ->and(groupReply($aliceWorkspace, $host, 'alice', ApprovalDecision::APPROVE)->status)->toBe('refused')
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval'])
        ->and(groupReply($bobWorkspace, $host, 'bob', ApprovalDecision::APPROVE)->accepted)->toBeTrue();
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount(1);
});

it('reports an undeliverable group without staging either requirement', function (ApprovalMode $mode, array $people): void {
    Event::fake([ApprovalUndeliverable::class]);
    $host = groupHost(new ApprovalRule($mode, array_values($people)), withPersonal: true);
    expect(app(Authorizer::class)->authorize($host->call(two: true))->status)->toBe('pending_approval')
        ->and(GovernanceApproval::query()->count())->toBe(0)
        ->and(GovernanceOutboxMessage::query()->count())->toBe(0);
    Event::assertDispatched(ApprovalUndeliverable::class);
    Queue::assertNothingPushed();
})->with([
    'empty' => [ApprovalMode::ANY, []],
    'outsider' => [ApprovalMode::ANY, ['bob', 'outsider']],
    'no distinct person' => [ApprovalMode::ANY, ['alice']],
    'mandatory person needed twice' => [ApprovalMode::ALL, ['alice', 'bob']],
]);

it('revokes when the host changes eligibility after a decision', function (): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['alice', 'bob']));
    app(Authorizer::class)->authorize($host->call());
    ApprovalHost::deliverCards();
    expect($host->decideFromInbox('alice'))->toBeTrue();

    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['bob']));
    ApprovalHost::runResumeJobs();
    expect(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'revoked'])
        ->and($host->executions)->toBe([])
        ->and(GovernanceGrant::query()->count())->toBe(0);
});

it('delivers one safe group preview and requires the original chat on every reply', function (): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['alice', 'bob']));
    $channel = new class implements GroupApprovalChannel
    {
        /** @var list<array{string, string, string}> */
        public array $cards = [];

        public function promptFor(Invocation $call, array $targets, array $eligiblePeople): GroupApprovalPrompt
        {
            expect($eligiblePeople)->toBe(['alice', 'bob']);

            return new GroupApprovalPrompt('family-chat', 'Move the appointment?');
        }

        public function allowsDisclosure(Scope $scope, GroupApprovalPrompt $prompt): bool
        {
            return true;
        }

        public function deliver(Scope $scope, string $deliveryId, GroupApprovalPrompt $prompt): string
        {
            $this->cards[] = [$deliveryId, $prompt->chatId, $prompt->preview];

            return 'group-card';
        }
    };
    app()->instance(GroupApprovalChannel::class, $channel);
    app(Authorizer::class)->authorize($host->call());
    ApprovalHost::deliverCards();
    expect($channel->cards)->toHaveCount(1);
    [[$deliveryId, $chatId, $preview]] = $channel->cards;
    $scope = $host->context->scope;
    expect($chatId)->toBe('family-chat')
        ->and($preview)->toBe('Move the appointment?')
        ->and($host->inbox->cards($scope, 'alice'))->toBe([])
        ->and($host->inbox->cards($scope, 'bob'))->toBe([]);

    $approvals = app(Approvals::class);
    $approve = ApprovalDecision::APPROVE;
    expect($approvals->respond($deliveryId, $approve, new VerifiedReply($scope, 'alice', 'group-card', 'a'))->status)
        ->toBe('refused');
    expect($approvals->respond($deliveryId, $approve, new VerifiedReply($scope, 'alice', 'group-card', 'a', $chatId))->accepted)
        ->toBeTrue();
    $late = $approvals->respond($deliveryId, ApprovalDecision::DECLINE, new VerifiedReply($scope, 'bob', 'group-card', 'b', $chatId));
    expect($late->decision)->toBe($approve)->and($late->accepted)->toBeFalse();
});
