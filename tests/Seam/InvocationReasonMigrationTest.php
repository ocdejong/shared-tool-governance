<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResumption;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalStore;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalCard;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

function deliverMigrationCards(): void
{
    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));
}

function approveMigrationCard(ApprovalHost $host, LocalCard $card): void
{
    $scope = $host->context->scope;
    expect(app(Approvals::class)->decide($card->approvalId, ApprovalDecision::APPROVE, new VerifiedReply($scope, 'alice', $card->cardId, 'reply-'.$card->cardId)))->toBeTrue();
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
}

it('upgrades pre-change reasons, preserves pending approval and restores only retained reasons on rollback', function (): void {
    $directory = dirname(__DIR__, 2).'/database/migrations';
    expect(Artisan::call('migrate', ['--realpath' => true, '--path' => [
        $directory.'/2026_11_24_000000_create_governance_tables.php',
        $directory.'/2026_11_24_000001_add_receipt_targets_to_governance_invocations.php',
    ]]))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:reset'))->toBe(0, Artisan::output());
    });

    Queue::fake();
    $host = new ApprovalHost;
    $host->bind();
    $policy = app(CallPolicy::class);
    $pending = $policy->freeze($host->call('pending'));
    // Recorded by the pre-workspace authorizer at c72f153.
    $baselineFingerprint = 'c0599d994eb15c8eeb34d9ca47e0c31bbacdb4583e1f73932646e56941846902';
    expect($pending->fingerprint())->toBe($baselineFingerprint);
    $decisions = $policy->evaluate($pending) ?? throw new LogicException('Expected a rule decision.');
    $requirements = $policy->approvalRequirements($pending, $decisions) ?? throw new LogicException('Expected approval requirements.');
    $inputs = $pending->inputs() + ['decisions' => $decisions, 'approval_requirements' => $requirements];
    $old = now()->subDays(31);
    $rows = [
        'legacy-window' => ['status' => 'pending_approval', 'reason' => $pending->call->reason, 'frozen_inputs' => $inputs],
        'pending' => ['status' => 'pending_approval', 'reason' => $pending->call->reason, 'frozen_inputs' => $inputs],
        'terminal' => ['status' => 'denied', 'reason' => 'Legacy terminal reason', 'frozen_inputs' => ['decisions' => []]],
        'pruned' => ['status' => 'denied', 'reason' => 'Legacy pruned reason', 'frozen_inputs' => null],
        'prune-in-window' => [
            'status' => 'denied', 'reason' => 'Expired legacy reason', 'frozen_inputs' => ['decisions' => []],
            'created_at' => $old, 'updated_at' => $old,
        ],
    ];

    foreach ($rows as $id => $fields) {
        $frozen = $policy->freeze($host->call($id));
        $row = GovernanceInvocation::query()->forceCreate($fields + [
            'host_scope' => 'tenant',
            'household_id' => 'home',
            'invocation_id' => $id,
            'tool_name' => 'reschedule',
            'tool_version' => '1',
            'action_key' => 'items.reschedule',
            'requester_id' => 'requester',
            'runtime_id' => 'runtime',
            'executor_id' => 'worker',
            'audience' => ['requester'],
            'fingerprint' => $id === 'pending' ? $baselineFingerprint : $frozen->fingerprint(),
        ]);
        if ($fields['status'] === 'pending_approval') {
            ApprovalStore::stage($row, $frozen, $requirements);
            ApprovalWork::deliverFor((int) $row->getKey());
        }
    }

    $terminal = DB::table('governance_invocations')->where('invocation_id', 'terminal');
    $terminalAt = $terminal->value('updated_at');
    $this->travel(1)->minute();

    deliverMigrationCards();
    $cards = $host->inbox->cards($host->context->scope, 'alice');
    expect($cards)->toHaveCount(2);
    [$legacyCard, $pendingCard] = $cards;
    approveMigrationCard($host, $legacyCard);
    expect(app(Approvals::class)->receipt('legacy-window'))->toBe(['status' => 'granted', 'outcome' => 'success']);

    // Install the deadline column so the current authorizer can run before the reason data migration.
    expect(Artisan::call('migrate', ['--realpath' => true, '--path' => [
        $directory.'/2026_11_24_000002_allow_encrypted_invocation_reasons.php',
        $directory.'/2026_11_24_000004_add_approval_deadline_to_governance_invocations.php',
    ]]))->toBe(0, Artisan::output())
        ->and(Schema::hasColumn('governance_invocations', 'reason'))->toBeTrue();
    $activity = app(Activity::class);
    $prunable = DB::table('governance_invocations')->where('invocation_id', 'prune-in-window');
    expect($activity->recentUse('prune-in-window')[0]['reason'] ?? null)->toBe('Expired legacy reason')
        ->and(Artisan::call('shared-tool-governance:prune'))->toBe(0, Artisan::output())
        ->and($prunable->value('reason'))->toBeNull()
        ->and($prunable->value('frozen_inputs'))->toBeNull()
        ->and($activity->recentUse('prune-in-window')[0])->not->toHaveKey('reason');

    $proposal = $host->call('transition');
    $transition = new Invocation(
        $proposal->id, $proposal->toolName, $proposal->toolVersion, $proposal->arguments,
        'Confidential transition reason', $proposal->context, $proposal->executorId,
    );
    DB::enableQueryLog();
    expect(app(Authorizer::class)->authorize($transition)->status)->toBe('pending_approval')
        ->and(DB::table('governance_invocations')->where('invocation_id', 'transition')->value('reason'))->toBeNull()
        ->and(json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR))->not->toContain($transition->reason);
    DB::disableQueryLog();

    expect(Artisan::call('migrate'))->toBe(0, Artisan::output())
        ->and($terminal->value('updated_at'))->toBe($terminalAt)
        ->and(Schema::hasColumn('governance_invocations', 'reason'))->toBeFalse();
    (require $directory.'/2026_11_24_000003_move_invocation_reason_into_frozen_inputs.php')->up();
    expect($activity->recentUse('terminal')[0]['reason'] ?? null)->toBe('Legacy terminal reason')
        ->and($activity->recentUse('pruned')[0])->not->toHaveKey('reason')
        ->and(DB::table('governance_invocations')->where('invocation_id', 'pruned')->value('frozen_inputs'))->toBeNull()
        ->and(DB::table('governance_invocations')->where('invocation_id', 'terminal')->value('frozen_inputs'))->not->toContain('Legacy terminal reason');

    expect(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->orderBy('id')->pluck('reason', 'invocation_id')->all())->toBe([
            'legacy-window' => $pending->call->reason,
            'pending' => $pending->call->reason,
            'terminal' => 'Legacy terminal reason',
            'pruned' => null,
            'prune-in-window' => null,
            'transition' => $transition->reason,
        ]);

    deliverMigrationCards();
    approveMigrationCard($host, $host->inbox->cards($host->context->scope, 'alice')[2]);
    expect(app(Approvals::class)->receipt('transition'))->toBe(['status' => 'granted', 'outcome' => 'success'])
        ->and(Artisan::call('migrate'))->toBe(0, Artisan::output());

    approveMigrationCard($host, $pendingCard);
    expect(app(Approvals::class)->receipt('pending'))->toBe(['status' => 'granted', 'outcome' => 'success'])
        ->and(array_column($host->executions, 'id'))->toBe(['legacy-window', 'transition', 'pending'])
        ->and(array_column($host->executions, 'reason'))->toBe([$pending->call->reason, $transition->reason, $pending->call->reason]);
});
