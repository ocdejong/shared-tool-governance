<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\ApproverPolicy;
use Ocdejong\SharedToolGovernance\Contracts\CompletionHandler;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\ResumptionContext;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\ApprovalRule;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final class AppointmentServiceProvider extends ServiceProvider implements ApproverPolicy, CompletionHandler, ResourceAuthority, ResumptionContext, ToolExecutor, TrustedInvocationContext
{
    public const ACTION = 'appointment.reschedule';

    public const TIME = '2026-11-24T15:00:00Z';

    public const SLOT = 'customer';

    public const DIRECT = 'olivier-slot';

    public const PAIRED = 'paired';

    public const UNANIMOUS = 'unanimous';

    public string $viewer = 'Olivier';

    public function register(): void
    {
        foreach ([self::class, ApproverPolicy::class, CompletionHandler::class, ResourceAuthority::class, ResumptionContext::class, TrustedInvocationContext::class] as $contract) {
            $this->app->instance($contract, $this);
        }
        $this->app->singleton(LocalApprovalChannel::class);
        $this->app->alias(LocalApprovalChannel::class, ApprovalNotifier::class);
        $this->app->alias(LocalApprovalChannel::class, GroupApprovalChannel::class);
    }

    public function boot(ToolRegistry $tools): void
    {
        Http::preventStrayRequests();

        $tools->register(new ToolDefinition(
            name: 'reschedule',
            version: '1',
            actionKey: self::ACTION,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'appointment' => ['enum' => [self::DIRECT, self::SLOT, self::PAIRED, self::UNANIMOUS]],
                    'time' => ['const' => self::TIME],
                ],
                'required' => ['appointment', 'time'],
                'additionalProperties' => false,
            ],
            normalizeArguments: static function (array $arguments): array {
                if (count($arguments) !== 2 || ($arguments['time'] ?? null) !== self::TIME
                    || ! in_array($arguments['appointment'] ?? null, [self::DIRECT, self::SLOT, self::PAIRED, self::UNANIMOUS], true)) {
                    throw new InvalidArgumentException('Select a fixture appointment and its permitted time.');
                }

                return ['appointment' => $arguments['appointment'], 'time' => self::TIME];
            },
            resolveTargets: static fn (array $arguments) => self::targets($arguments['appointment']),
            executorId: self::class,
            effect: 'Move the selected fixture appointment',
            resultSchema: ['type' => 'object', 'properties' => ['time' => ['type' => 'string']]],
            approvalPreview: static fn (Invocation $call, array $targets) => new ApprovalPreview(
                $call->reason,
                'Move '.implode(', ', array_map(static fn (ResourceReference $target) => $target->id, $targets))
                    .' to '.$call->arguments['time'],
            ),
        ));
    }

    /** @return list<ResourceReference> */
    public static function targets(string $appointment): array
    {
        $ids = $appointment === self::PAIRED ? [self::DIRECT, 'pauline-slot'] : [$appointment];

        return array_map(static fn (string $id) => new ResourceReference('appointment', $id), $ids);
    }

    public static function scope(): Scope
    {
        return new Scope('appointment-example', workspaceId: 'scheduling-team');
    }

    public function current(): InvocationContext
    {
        return new InvocationContext(self::scope(), $this->viewer, 'appointment-cli', [$this->viewer]);
    }

    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext
    {
        $requester = Cache::get('appointment.request.'.$invocationId);

        return is_string($requester) && $this->isMember($scope, $requester)
            ? new InvocationContext($scope, $requester, 'appointment-cli', [$requester]) : null;
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope->equals(self::scope()) && in_array($personId, ['Olivier', 'Pauline', 'scheduler', 'observer'], true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        if (! $scope->equals(self::scope()) || $resource->type !== 'appointment') {
            return null;
        }

        return match ($resource->id) {
            self::SLOT, self::UNANIMOUS => new OwnerReference(OwnerType::WORKSPACE, $scope->workspaceId),
            self::DIRECT => new OwnerReference(OwnerType::PERSON, 'Olivier'),
            'pauline-slot' => new OwnerReference(OwnerType::PERSON, 'Pauline'),
            default => null,
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $this->ownerOf($scope, $resource) == $owner
            && $personId === ($owner->type === OwnerType::WORKSPACE ? 'Olivier' : $owner->id);
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string
    {
        return $owner->type === OwnerType::PERSON && $this->ownerOf($scope, $resource) == $owner ? $owner->id : null;
    }

    public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?ApprovalRule
    {
        return $owner->type === OwnerType::WORKSPACE && $this->ownerOf($scope, $resource) == $owner
            ? new ApprovalRule($resource->id === self::UNANIMOUS ? ApprovalMode::ALL : ApprovalMode::ANY, ['Olivier', 'Pauline'])
            : null;
    }

    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult
    {
        if ($approvedTargets != self::targets($frozenCall->arguments['appointment'])) {
            return new ExecutionResult(ExecutionStatus::FAILED);
        }

        $time = $frozenCall->arguments['time'];
        Cache::increment('appointment.executions.'.$frozenCall->id);
        foreach ($approvedTargets as $target) {
            Cache::put('appointment.time.'.$target->id, $time);
        }

        return new ExecutionResult(ExecutionStatus::SUCCEEDED, ['time' => $time]);
    }

    public function completed(Scope $scope, string $invocationId, ExecutionResult $result): void
    {
        $context = $this->forInvocation($scope, $invocationId);
        if ($context !== null) {
            Cache::put('appointment.result.'.$context->requesterId.'.'.$invocationId, $result->result);
        }
    }
}
