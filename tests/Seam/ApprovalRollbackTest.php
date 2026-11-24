<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Authorization\InvocationStore;
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
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

/** @return list<string> */
function approvalRollbackMigrations(): array
{
    return glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
}

/** @return array<string, mixed> */
function approvalRollbackState(): array
{
    $state = [];
    foreach ([
        'governance_resource_bindings', 'governance_rule_revisions', 'governance_invocations',
        'governance_approvals', 'governance_outbox_messages', 'governance_audit_events', 'governance_grants',
    ] as $table) {
        $state[$table] = [
            'columns' => Schema::getColumns($table),
            'indexes' => Schema::getIndexes($table),
            'rows' => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
    $state['migrations'] = DB::table('migrations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

    return $state;
}

function seedLegacyRollbackCall(Invocation $call): void
{
    $policy = app(CallPolicy::class);
    $frozen = $policy->freeze($call);
    $decisions = $policy->evaluate($frozen) ?? throw new LogicException('Expected legacy rule decisions.');
    $requirements = $policy->approvalRequirements($frozen, $decisions) ?? throw new LogicException('Expected legacy approvers.');
    $context = $call->context;
    $issuedAt = now();
    $row = GovernanceInvocation::query()->forceCreate([
        'host_scope' => $context->scope->hostScope,
        'household_id' => $context->scope->workspaceId,
        'invocation_id' => $call->id,
        'tool_name' => $call->toolName,
        'tool_version' => $call->toolVersion,
        'action_key' => $frozen->tool->actionKey,
        'requester_id' => $context->requesterId,
        'runtime_id' => $context->runtimeId,
        'executor_id' => $call->executorId,
        'audience' => $context->audience,
        'fingerprint' => $frozen->fingerprint(),
        'status' => 'pending_approval',
        'frozen_inputs' => $frozen->inputs() + ['reason' => $call->reason, 'decisions' => $decisions, 'approval_requirements' => $requirements],
        'created_at' => $issuedAt,
        'updated_at' => $issuedAt,
    ]);
    InvocationStore::audit($row, 'pending_approval');

    foreach ($requirements as $requirement) {
        $person = $requirement['approver_id'] ?? throw new LogicException('Expected a named approver.');
        $id = (string) Str::ulid();
        DB::table('governance_approvals')->insert([
            'invocation_row_id' => $row->getKey(),
            'approval_id' => $id,
            'owner_type' => $requirement['owner_type'],
            'owner_id' => $requirement['owner_id'],
            'approver_id' => $person,
            'expires_at' => $issuedAt->copy()->addMinutes(15),
            'preview' => 'Legacy private approval',
        ]);
        DB::table('governance_outbox_messages')->insert([
            'invocation_row_id' => $row->getKey(),
            'delivery_id' => $id,
            'recipient_id' => $person,
            'card_id' => 'legacy-'.$id,
            'delivered_at' => $issuedAt,
            'payload' => 'Legacy private approval',
        ]);
    }
}

/** @param array<string, mixed> $options */
function assertApprovalRollbackRefused(array $options): void
{
    $before = approvalRollbackState();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(fn () => Artisan::call('migrate:rollback', $options))
            ->toThrow(RuntimeException::class, 'Keep the current governance schema and compatible workers');
        expect(approvalRollbackState())->toBe($before);
    }
}

beforeEach(function (): void {
    Queue::fake();
    expect(Artisan::call('migrate', ['--realpath' => true, '--path' => array_slice(approvalRollbackMigrations(), 0, 4)]))->toBe(0, Artisan::output());
    // Each populated rollback test keeps its evidence through its final assertions.
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('db:wipe', ['--force' => true]))->toBe(0, Artisan::output());
    });
});

it('rolls the adoption batch back and reapplies it over legacy single and separate named owners', function (): void {
    $host = new ApprovalHost;
    $host->bind();
    seedLegacyRollbackCall($host->call('legacy-single'));
    seedLegacyRollbackCall($host->call('legacy-separate', two: true));

    $baseline = approvalRollbackState();
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $upgraded = approvalRollbackState();
    expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output())
        ->and(approvalRollbackState())->toBe($baseline);

    $duplicate = (array) DB::table('governance_approvals')->oldest('id')->first();
    unset($duplicate['id']);
    expect(fn () => DB::table('governance_approvals')->insert(array_replace($duplicate, [
        'approval_id' => (string) Str::ulid(),
        'approver_id' => 'bob',
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $reapplied = approvalRollbackState();
    expect(array_column($reapplied['migrations'], 'migration', 'batch'))->toBe(array_column($upgraded['migrations'], 'migration', 'batch'));
    unset($upgraded['migrations'], $reapplied['migrations']);
    expect($reapplied)->toBe($upgraded);

    $approvals = app(Approvals::class);
    foreach (['legacy-single', 'legacy-separate'] as $id) {
        $rowId = DB::table('governance_invocations')->where('invocation_id', $id)->value('id');
        $cards = DB::table('governance_outbox_messages')->where('invocation_row_id', $rowId)->orderBy('id')->get();
        expect($cards)->toHaveCount($id === 'legacy-single' ? 1 : 2);
        foreach ($cards as $card) {
            expect($approvals->decide($card->delivery_id, ApprovalDecision::APPROVE, new VerifiedReply(
                $host->context->scope, $card->recipient_id, $card->card_id, 'reply-'.$card->delivery_id,
            )))->toBeTrue();
        }
    }
    ApprovalHost::runResumeJobs();
    expect(array_column($host->executions, 'id'))->toBe(['legacy-single', 'legacy-separate'])
        ->and($approvals->receipt('legacy-single'))->toBe(['status' => 'granted', 'outcome' => 'success'])
        ->and($approvals->receipt('legacy-separate'))->toBe(['status' => 'granted', 'outcome' => 'success']);
});

it('refuses a lossy approval rollback twice and keeps delivered replies usable', function (
    ApprovalMode $mode, array $people, bool $shared,
): void {
    [$eligibleMigration, $groupMigration] = array_slice(approvalRollbackMigrations(), 5);
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());

    $host = new ApprovalHost;
    $host->allHouseholdOwned = true;
    $host->bind();
    $rule = new ApprovalRule($mode, array_values($people));
    app()->instance(ApproverPolicy::class, new class($rule) implements ApproverPolicy
    {
        public function __construct(private ApprovalRule $rule) {}

        public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ApprovalRule
        {
            return $this->rule;
        }
    });
    if ($shared) {
        app()->instance(GroupApprovalChannel::class, new class implements GroupApprovalChannel
        {
            public function promptFor(Invocation $call, array $targets, array $eligiblePeople): GroupApprovalPrompt
            {
                return new GroupApprovalPrompt('family-chat', 'Move the appointment?');
            }

            public function allowsDisclosure(Scope $scope, GroupApprovalPrompt $prompt): bool
            {
                return true;
            }

            public function deliver(Scope $scope, string $deliveryId, GroupApprovalPrompt $prompt): string
            {
                return 'group-card';
            }
        });
    }
    expect(app(Authorizer::class)->authorize($host->call())->status)->toBe('pending_approval');
    ApprovalHost::deliverCards();
    $approvals = app(Approvals::class);
    $delivery = DB::table('governance_outbox_messages')->where('recipient_id', $shared ? 'family-chat' : 'alice')->sole();
    $alice = new VerifiedReply($host->context->scope, 'alice', $delivery->card_id, 'reply-alice', $shared ? 'family-chat' : null);
    expect($approvals->respond($delivery->delivery_id, ApprovalDecision::APPROVE, $alice)->accepted)->toBeTrue();
    ApprovalHost::runResumeJobs();

    $beforeReceipt = $approvals->receipt('visit');
    $activity = app(Activity::class);
    $beforeActivity = $activity->recentUse('visit');
    expect($beforeReceipt)->toBe($mode === ApprovalMode::ALL
        ? ['status' => 'pending_approval']
        : ['status' => 'granted', 'outcome' => 'success'])
        ->and(DB::table('governance_approvals')->orderBy('approver_id')->pluck('approver_id')->all())->toBe($people)
        ->and(DB::table('governance_approvals')->where('approver_id', 'alice')->value('reply_id'))->toBe('reply-alice')
        ->and(DB::table('governance_approvals')->whereNotNull('group_delivery_id')->count())->toBe($shared ? count($people) : 0);

    assertApprovalRollbackRefused([]);
    assertApprovalRollbackRefused(['--step' => 2]);
    assertApprovalRollbackRefused(['--batch' => 2]);
    assertApprovalRollbackRefused(['--step' => 2, '--realpath' => true, '--path' => [$eligibleMigration]]);

    $groupOnly = ['--step' => 1, '--realpath' => true, '--path' => [$groupMigration]];
    if ($shared) {
        assertApprovalRollbackRefused($groupOnly);
    } else {
        $beforeGroupOnly = approvalRollbackState();
        expect(Artisan::call('migrate:rollback', $groupOnly))->toBe(0, Artisan::output())
            ->and(Schema::hasColumn('governance_approvals', 'group_delivery_id'))->toBeFalse()
            ->and(Schema::hasColumn('governance_approvals', 'approval_mode'))->toBeTrue();
        assertApprovalRollbackRefused(['--step' => 1, '--realpath' => true, '--path' => [$eligibleMigration]]);
        expect($approvals->respond($delivery->delivery_id, ApprovalDecision::APPROVE, $alice)->accepted)->toBeTrue()
            ->and(Artisan::call('migrate'))->toBe(0, Artisan::output());
        $afterReapply = approvalRollbackState();
        $expectedBatches = array_column($beforeGroupOnly['migrations'], 'batch', 'migration');
        $expectedBatches[basename($groupMigration, '.php')]++;
        expect(array_column($afterReapply['migrations'], 'batch', 'migration'))->toBe($expectedBatches);
        unset($beforeGroupOnly['migrations'], $afterReapply['migrations']);
        expect($afterReapply)->toBe($beforeGroupOnly);
    }

    expect($approvals->receipt('visit'))->toBe($beforeReceipt)
        ->and($activity->recentUse('visit'))->toBe($beforeActivity)
        ->and($approvals->respond($delivery->delivery_id, ApprovalDecision::APPROVE, $alice)->accepted)->toBeTrue();
    if ($mode === ApprovalMode::ALL) {
        expect($host->executions)->toBe([])
            ->and($host->decideFromInbox('bob'))->toBeTrue();
        ApprovalHost::runResumeJobs();
    }
    expect($host->executions)->toHaveCount(1)
        ->and($approvals->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success']);
})->with([
    'private pending all' => [ApprovalMode::ALL, ['alice', 'bob'], false],
    'private settled any with one voter' => [ApprovalMode::ANY, ['alice'], false],
    'shared settled any' => [ApprovalMode::ANY, ['alice', 'bob'], true],
    'shared settled any with one voter' => [ApprovalMode::ANY, ['alice'], true],
]);
