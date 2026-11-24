<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResponse;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalUndeliverable;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\ApproverPolicy;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\ApprovalRule;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalCard;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
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

it('keeps independent requirements separate when their eligible people overlap', function (bool $groupFirst): void {
    $host = groupHost(new ApprovalRule(ApprovalMode::ANY, ['alice', 'bob']), withPersonal: true);
    app(Authorizer::class)->authorize($host->call(two: true));
    ApprovalHost::deliverCards();
    $scope = $host->context->scope;
    [$personal, $group] = $host->inbox->cards($scope, 'alice');
    $bob = $host->inbox->cards($scope, 'bob')[0];
    expect($personal->message)->toContain('personal')->not->toContain('shared')
        ->and($group->message)->toContain('shared')->not->toContain('personal');

    expect(groupReply($groupFirst ? $group : $personal, $host, 'alice', ApprovalDecision::APPROVE)->accepted)->toBeTrue()
        ->and(groupReply($groupFirst ? $personal : $group, $host, 'alice', ApprovalDecision::APPROVE)->status)->toBe('refused')
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'pending_approval'])
        ->and(GovernanceApproval::query()->whereNotNull('decision')->count())->toBe(1);

    expect(groupReply($bob, $host, 'bob', ApprovalDecision::APPROVE)->accepted)->toBe(! $groupFirst);
    ApprovalHost::runResumeJobs();
    expect($host->executions)->toHaveCount($groupFirst ? 0 : 1);
})->with([false, true]);

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
