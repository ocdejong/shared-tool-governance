<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tests\Approvals;

use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;
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
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;
use RuntimeException;

final class ApprovalHost implements ResourceAuthority, ResumptionContext, RuleEvaluator, ToolExecutor, TrustedInvocationContext
{
    public InvocationContext $context;

    public bool $workerOnly = false;

    /** @var list<string> */
    public array $members = ['requester', 'alice', 'bob'];

    public string $revision = 'r1';

    public ?string $householdApprover = 'bob';

    public string $personalOwner = 'alice';

    public bool $deny = false;

    public bool $interrupt = false;

    public bool $denyAtRedemption = false;

    public bool $timeout = false;

    public int $checksAfterApproval = 0;

    /** @var list<Invocation> */
    public array $executions = [];

    public function __construct(public readonly LocalInbox $inbox = new LocalInbox)
    {
        $this->context = new InvocationContext(new Scope('tenant', 'home'), 'requester', 'runtime', ['requester']);
    }

    public function bind(string $executorId = 'worker'): void
    {
        foreach ([ResourceAuthority::class, ResumptionContext::class, RuleEvaluator::class, ToolExecutor::class, TrustedInvocationContext::class] as $contract) {
            app()->instance($contract, $this);
        }

        app()->instance(ApprovalNotifier::class, $this->inbox);
        $tools = new ToolRegistry;
        $tools->register(new ToolDefinition(
            name: 'reschedule',
            version: '1',
            actionKey: 'items.reschedule',
            inputSchema: [],
            normalizeArguments: static fn (array $arguments): array => $arguments + ['timezone' => 'Europe/Amsterdam'],
            resolveTargets: fn (array $arguments): array => [
                new ResourceReference('item', 'personal', $this->revision),
                ...($arguments['two'] ? [new ResourceReference('item', 'shared', 'r2')] : []),
            ],
            executorId: $executorId,
            effect: 'Reschedule the selected items',
            resultSchema: [],
            approvalPreview: static fn (Invocation $call, array $targets, string $approver): ApprovalPreview => new ApprovalPreview(
                'Move the repair visit',
                implode(', ', array_column($targets, 'id')).' at 09:00 → '.$call->arguments['scheduled_time'],
            ),
        ));
        app()->instance(ToolRegistry::class, $tools);
    }

    public function call(string $id = 'visit', bool $two = false): Invocation
    {
        return new Invocation(
            $id, 'reschedule', '1', ['two' => $two, 'scheduled_time' => '10:00', 'private_note' => 'hidden detail'],
            'Move the repair visit (hidden detail)', $this->context, 'worker',
        );
    }

    public function current(): InvocationContext
    {
        if ($this->workerOnly) {
            throw new RuntimeException('Ambient request context is unavailable in the worker.');
        }

        return $this->context;
    }

    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext
    {
        $this->checksAfterApproval++;

        if ($this->checksAfterApproval === 2) {
            if ($this->interrupt) {
                throw new RuntimeException('Worker stopped after issuance.');
            }

            if ($this->denyAtRedemption) {
                $this->deny = true;
            }
        }

        return $scope->equals($this->context->scope) ? $this->context : null;
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope->equals($this->context->scope) && in_array($personId, $this->members, true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        return match ($resource->id) {
            'personal' => new OwnerReference(OwnerType::PERSON, $this->personalOwner),
            'shared' => new OwnerReference(OwnerType::HOUSEHOLD, $scope->householdId),
            default => null,
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $this->approverFor($scope, $resource, $owner) === $personId;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string
    {
        return $owner->type === OwnerType::PERSON ? $owner->id : $this->householdApprover;
    }

    public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation
    {
        return new RuleEvaluation($this->deny ? Verdict::DENY : Verdict::DELEGATE, $actionKey, 1, 'Test rule');
    }

    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult
    {
        $this->executions[] = $frozenCall;
        if ($this->timeout) {
            throw new RuntimeException('Executor timed out.');
        }

        return new ExecutionResult(ExecutionStatus::SUCCEEDED, ['raw' => 'hidden result'], 'hidden provider reference');
    }
}
