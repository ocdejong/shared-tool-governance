<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\ResumptionContext;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
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

final class PermissionTreeServiceProvider extends ServiceProvider implements ApprovalNotifier, ResourceAuthority, ResumptionContext, ToolExecutor, TrustedInvocationContext
{
    public string $viewer = 'pauline';

    public string $workspace = 'home';

    public int $executions = 0;

    /** @var array<string, string|null> */
    public array $times = ['pauline' => '09:00', 'tom' => '10:00'];

    public function register(): void
    {
        foreach ([self::class, ApprovalNotifier::class, ResourceAuthority::class, ResumptionContext::class, TrustedInvocationContext::class] as $contract) {
            $this->app->instance($contract, $this);
        }
    }

    public function boot(ToolRegistry $tools): void
    {
        Http::preventStrayRequests();

        foreach ([
            'availability' => 'calendar.availability',
            'reschedule' => 'calendar.events.reschedule',
            'delete' => 'calendar.events.delete',
        ] as $name => $action) {
            $tools->register(new ToolDefinition(
                name: $name,
                version: '1',
                actionKey: $action,
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['calendar' => ['enum' => ['pauline', 'tom']], 'time' => ['type' => 'string']],
                    'required' => $name === 'reschedule' ? ['calendar', 'time'] : ['calendar'],
                    'additionalProperties' => false,
                ],
                normalizeArguments: static function (array $arguments) use ($name): array {
                    if (! in_array($arguments['calendar'] ?? null, ['pauline', 'tom'], true)
                        || array_diff(array_keys($arguments), ['calendar', 'time']) !== []
                        || (array_key_exists('time', $arguments) && ! is_string($arguments['time']))
                        || ($name === 'reschedule' && ! is_string($arguments['time'] ?? null))) {
                        throw new InvalidArgumentException('Select a fixture calendar and supply a time when rescheduling.');
                    }

                    return $arguments;
                },
                resolveTargets: static fn (array $arguments) => [new ResourceReference('calendar', $arguments['calendar'])],
                executorId: self::class,
                effect: $name.' on the selected fixture calendar',
                resultSchema: ['type' => 'object'],
                approvalPreview: static fn (Invocation $call) => new ApprovalPreview($call->reason, $name.' on '.$call->arguments['calendar'].' calendar'),
            ));
        }
    }

    public function current(): InvocationContext
    {
        return new InvocationContext(new Scope('permission-tree-example', $this->workspace), $this->viewer, 'settings-demo', [$this->viewer]);
    }

    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext
    {
        $requester = Cache::get('permission-tree.request.'.$invocationId);

        return is_string($requester) && $this->isMember($scope, $requester)
            ? new InvocationContext($scope, $requester, 'settings-demo', [$requester]) : null;
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope->equals($this->current()->scope) && in_array($personId, ['pauline', 'tom'], true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        return $resource->type === 'calendar' && $this->isMember($scope, $resource->id)
            ? new OwnerReference(OwnerType::PERSON, $resource->id) : null;
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $this->ownerOf($scope, $resource) == $owner && $personId === $owner->id;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string
    {
        return $this->ownerOf($scope, $resource) == $owner ? $owner->id : null;
    }

    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult
    {
        $calendar = $frozenCall->arguments['calendar'];
        if ($approvedTargets != [new ResourceReference('calendar', $calendar)]) {
            return new ExecutionResult(ExecutionStatus::FAILED);
        }

        $this->executions++;
        $result = match ($frozenCall->toolName) {
            'availability' => ['available' => $this->times[$calendar] === null],
            'reschedule' => ['time' => $this->times[$calendar] = $frozenCall->arguments['time']],
            'delete' => ['time' => $this->times[$calendar] = null],
            default => throw new InvalidArgumentException('Unknown fixture tool.'),
        };

        return new ExecutionResult(ExecutionStatus::SUCCEEDED, $result);
    }

    public function deliver(Scope $scope, string $deliveryId, string $recipientId, string $message): string
    {
        Cache::put('permission-tree.card.'.$recipientId.'.'.$deliveryId, $message);

        return $deliveryId;
    }
}
