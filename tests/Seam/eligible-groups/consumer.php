<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalResponse;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\ResumptionContext;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

require __DIR__.'/vendor/autoload.php';

final class EligibleGroupsConsumer implements ApprovalNotifier, ResourceAuthority, ResumptionContext, RuleEvaluator, ToolExecutor, TrustedInvocationContext
{
    public function bind(): void
    {
        foreach ([ApprovalNotifier::class, ResourceAuthority::class, ResumptionContext::class, RuleEvaluator::class, TrustedInvocationContext::class, 'appointment-executor'] as $contract) {
            app()->instance($contract, $this);
        }

        app(ToolRegistry::class)->register(new ToolDefinition(
            name: 'reschedule',
            version: '1',
            actionKey: 'appointments.reschedule',
            inputSchema: [],
            normalizeArguments: static fn (array $arguments): array => $arguments,
            resolveTargets: static fn (array $arguments): array => array_map(
                static fn (string $target): ResourceReference => new ResourceReference('appointment', $target, '1'),
                array_values($arguments['targets']),
            ),
            executorId: 'appointment-executor',
            effect: 'Reschedule the appointments',
            resultSchema: [],
            approvalPreview: static fn (Invocation $call, array $targets): ApprovalPreview => new ApprovalPreview(
                'Reschedule appointments',
                implode(', ', array_column($targets, 'id')).' at '.$call->arguments['time'],
            ),
        ));
    }

    /** @param list<string> $targets */
    public function request(string $id, array $targets): void
    {
        $call = new Invocation($id, 'reschedule', '1', ['targets' => $targets, 'time' => '10:00'], 'Move appointments', $this->current(), 'appointment-executor');
        check(app(Authorizer::class)->authorize($call)->status === 'pending_approval', "$id did not request approval");
    }

    public function current(): InvocationContext
    {
        return new InvocationContext(new Scope('consumer', 'team'), 'requester', 'runtime', ['requester']);
    }

    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext
    {
        if (! $scope->equals($this->current()->scope)) {
            return null;
        }

        return $invocationId === 'audience' && is_file(storage_path('authority-changed'))
            ? new InvocationContext($scope, 'requester', 'runtime', ['requester', 'child'])
            : $this->current();
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope->equals($this->current()->scope) && in_array($personId, ['requester', 'Olivier', 'Pauline', 'child'], true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): OwnerReference
    {
        return match ($resource->id) {
            'personal', 'named-olivier' => new OwnerReference(OwnerType::PERSON, 'Olivier'),
            'separate', 'named-pauline' => new OwnerReference(OwnerType::PERSON, 'Pauline'),
            'overlap-personal' => new OwnerReference(OwnerType::PERSON, 'child'),
            default => new OwnerReference(OwnerType::WORKSPACE, $scope->workspaceId),
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $this->approverFor($scope, $resource, $owner) === $personId;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): string
    {
        if ($owner->type === OwnerType::PERSON) {
            return $owner->id;
        }

        return $resource->id === 'combined' ? 'Olivier' : 'Pauline';
    }

    public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation
    {
        return new RuleEvaluation(Verdict::DELEGATE, $actionKey, 1, 'Synthetic appointment rule');
    }

    public function deliver(Scope $scope, string $deliveryId, string $recipientId, string $message): string
    {
        $cardId = 'private-card-'.$deliveryId;
        $card = json_encode([$deliveryId, $cardId, $recipientId, $message], JSON_THROW_ON_ERROR);
        check(file_put_contents(storage_path('cards.jsonl'), $card.PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Could not persist the private card');

        return $cardId;
    }

    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult
    {
        check(DB::transactionLevel() === 0, 'Executor ran before redemption committed');
        check($frozenCall->arguments['time'] === '10:00', 'Worker did not execute the approved arguments');
        check(file_put_contents(storage_path('executions'), $frozenCall->id.PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Could not persist execution');

        return new ExecutionResult(ExecutionStatus::SUCCEEDED, ['time' => '10:00']);
    }

    public function reply(string $target, string $person, ApprovalDecision $decision): ApprovalResponse
    {
        $matches = [];
        foreach (lines('cards.jsonl') as $line) {
            [$deliveryId, $cardId, $recipient, $message] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($recipient === $person && str_contains($message, $target.' at 10:00')) {
                $matches[] = [$deliveryId, $cardId];
            }
        }
        check(count($matches) === 1, "Expected one matching card for $person and $target");
        [$deliveryId, $cardId] = $matches[0];

        return app(Approvals::class)->respond(
            $deliveryId, $decision, new VerifiedReply($this->current()->scope, $person, $cardId, 'reply-'.$cardId),
        );
    }
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function lines(string $file): array
{
    $lines = file(storage_path($file), FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Could not read $file");
    }

    return $lines;
}

function worker(string $queue = 'default', int $tries = 1): void
{
    check(Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => $tries, '--queue' => $queue, '--sleep' => 0]) === 0, 'The worker failed');
    check(DB::table('failed_jobs')->count() === 0, Artisan::output());
}

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$host = new EligibleGroupsConsumer;
$host->bind();
$approve = ApprovalDecision::APPROVE;

switch ($argv[1] ?? '') {
    case 'seed':
        $host->request('single', ['single']);
        $host->request('separate', ['personal', 'separate']);
        $host->request('combined', ['personal', 'combined']);
        break;
    case 'upgrade':
        check($host->reply('single', 'Pauline', $approve)->accepted, 'Legacy single approval was refused');
        check($host->reply('personal', 'Olivier', $approve)->accepted, 'First separate legacy owner was refused');
        check(app(Approvals::class)->receipt('separate') === ['status' => 'pending_approval'], 'One separate owner settled both requirements');
        check($host->reply('separate', 'Pauline', $approve)->accepted, 'Second separate legacy owner was refused');
        check($host->reply('combined', 'Olivier', $approve)->status === 'refused', 'Combined legacy card was accepted');
        check(app(Approvals::class)->receipt('combined') === ['status' => 'pending_approval'], 'Combined call did not stay pending');
        break;
    case 'verify-upgrade':
        foreach (['single', 'separate'] as $id) {
            check(app(Approvals::class)->receipt($id) === ['status' => 'granted', 'outcome' => 'success'], "$id did not complete");
        }
        Date::setTestNow(now()->addMinutes(16));
        app(ApprovalWork::class)->recover();
        worker();
        check(DB::table('governance_invocations')->where('invocation_id', 'combined')->value('status') === 'expired', 'Combined legacy call did not expire');
        check(lines('executions') === ['single', 'separate'], 'The wrong legacy calls executed');
        check(DB::table('governance_grants')->count() === 2, 'The wrong number of legacy grants was issued');
        break;
    case 'worker':
        worker();
        break;
    default:
        require __DIR__.'/GroupChat.php';
        require __DIR__.'/group-scenarios.php';
        groupScenario($host, $argv[1] ?? '');
}
