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
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalDelivery;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

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
    $decisions = $policy->evaluate($pending) ?? throw new LogicException('Expected a rule decision.');
    $requirements = $policy->approvalRequirements($pending, $decisions) ?? throw new LogicException('Expected approval requirements.');
    $inputs = $pending->inputs() + ['decisions' => $decisions, 'approval_requirements' => $requirements];
    $rows = [
        'pending' => ['status' => 'pending_approval', 'reason' => $pending->call->reason, 'frozen_inputs' => $inputs],
        'terminal' => ['status' => 'denied', 'reason' => 'Legacy terminal reason', 'frozen_inputs' => ['decisions' => []]],
        'pruned' => ['status' => 'denied', 'reason' => 'Legacy pruned reason', 'frozen_inputs' => null],
    ];

    foreach ($rows as $id => $fields) {
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
            'fingerprint' => $pending->fingerprint(),
        ]);
        if ($id === 'pending') {
            ApprovalStore::stage($row, $pending, $requirements);
            $pendingId = (int) $row->getKey();
        }
    }

    $terminal = DB::table('governance_invocations')->where('invocation_id', 'terminal');
    $terminalAt = $terminal->value('updated_at');
    $this->travel(1)->minute();

    expect(Artisan::call('migrate'))->toBe(0, Artisan::output())
        ->and($terminal->value('updated_at'))->toBe($terminalAt)
        ->and(Schema::hasColumn('governance_invocations', 'reason'))->toBeFalse();
    (require $directory.'/2026_11_24_000002_move_invocation_reason_into_frozen_inputs.php')->up();
    $activity = app(Activity::class);
    expect($activity->recentUse('terminal')[0]['reason'] ?? null)->toBe('Legacy terminal reason')
        ->and($activity->recentUse('pruned')[0])->not->toHaveKey('reason')
        ->and(DB::table('governance_invocations')->where('invocation_id', 'pruned')->value('frozen_inputs'))->toBeNull()
        ->and(DB::table('governance_invocations')->where('invocation_id', 'terminal')->value('frozen_inputs'))->not->toContain('Legacy terminal reason');

    expect(Artisan::call('migrate:rollback', ['--step' => 1]))->toBe(0, Artisan::output())
        ->and(DB::table('governance_invocations')->orderBy('id')->pluck('reason', 'invocation_id')->all())->toBe([
            'pending' => $pending->call->reason,
            'terminal' => 'Legacy terminal reason',
            'pruned' => null,
        ])
        ->and(Artisan::call('migrate'))->toBe(0, Artisan::output());

    ApprovalWork::deliverFor($pendingId);
    Queue::pushed(DeliverApproval::class)
        ->each(static fn (DeliverApproval $job) => $job->handle(app(ApprovalDelivery::class)));
    $scope = $host->context->scope;
    $card = $host->inbox->cards($scope, 'alice')[0];
    $approvals = app(Approvals::class);
    expect($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, new VerifiedReply($scope, 'alice', $card->cardId, 'legacy-reply')))->toBeTrue();
    Queue::pushed(ResumeApproval::class)
        ->each(static fn (ResumeApproval $job) => $job->handle(app(ApprovalResumption::class)));
    expect($approvals->receipt('pending'))->toBe(['status' => 'granted', 'outcome' => 'success'])
        ->and($host->executions)->toHaveCount(1)
        ->and($host->executions[0]->reason)->toBe($pending->call->reason);
});
