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
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryAccess;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryTarget;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final class TwoOwnerServiceProvider extends ServiceProvider implements ResourceAuthority, ResumptionContext, ToolExecutor, TrustedInvocationContext
{
    public const CALL_ID = 'planning-call';

    private const DATE = '2026-12-02';

    private const AVAILABILITY = 'joint_availability';

    private const MEMBERS = ['Pauline', 'Tom'];

    private string $viewer = 'Tom';

    public function register(): void
    {
        foreach ([self::class, ResourceAuthority::class, ResumptionContext::class, TrustedInvocationContext::class] as $contract) {
            $this->app->instance($contract, $this);
        }
        $this->app->singleton(LocalInbox::class);
        $this->app->alias(LocalInbox::class, ApprovalNotifier::class);

        $tools = [
            $this->calendarTool(self::AVAILABILITY, 'calendar.availability', 'Suggest common times'),
            $this->calendarTool('event_details', 'calendar.details', 'Read event details'),
        ];
        $registry = new ToolRegistry;
        foreach ($tools as $tool) {
            $registry->register($tool);
        }
        $this->app->instance(ToolRegistry::class, $registry);
        $this->app->bind(ToolDiscovery::class, fn () => new ToolDiscovery(
            $this, $this, app(RuleEvaluator::class), $tools, static fn () => DiscoveryAccess::VISIBLE,
        ));
    }

    public function boot(): void
    {
        Http::preventStrayRequests();
    }

    public function seedOwnerRules(): void
    {
        $rules = app(ResourceRules::class);
        $scope = $this->current()->scope;
        foreach ($this->discoveryTargets() as $target) {
            $this->viewer = $target->owner->id;
            $pauline = $this->viewer === 'Pauline';
            $rules->setRules($scope, $target->resource ?? throw new InvalidArgumentException('Calendar missing.'), 0, [
                'rules' => [
                    'calendar' => $pauline ? Verdict::DENY : Verdict::ALLOW,
                    'calendar.availability' => $pauline ? Verdict::DELEGATE : Verdict::ALLOW,
                ],
            ]);
        }
        $this->viewer = 'Tom';
    }

    public function current(): InvocationContext
    {
        return new InvocationContext(self::scope(), $this->viewer, 'demo-runtime', self::MEMBERS);
    }

    public function viewAs(string $person): void
    {
        if (! $this->isMember(self::scope(), $person)) {
            throw new InvalidArgumentException('Expected a member of the demo household.');
        }

        $this->viewer = $person;
    }

    public function forInvocation(Scope $scope, string $invocationId): ?InvocationContext
    {
        $request = Cache::store('database')->get('demo.request-context');
        if (! is_array($request) || $request['id'] !== $invocationId || ! $this->isMember($scope, $request['requester'])) {
            return null;
        }

        return new InvocationContext($scope, $request['requester'], $request['runtime'], self::MEMBERS);
    }

    private static function scope(): Scope
    {
        return new Scope('two-owner-demo', 'planning');
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope->equals(self::scope()) && in_array($personId, self::MEMBERS, true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        if (! $scope->equals(self::scope()) || $resource->type !== 'calendar') {
            return null;
        }

        return match ($resource->id) {
            'tom-calendar' => new OwnerReference(OwnerType::PERSON, 'Tom'),
            'pauline-calendar' => new OwnerReference(OwnerType::PERSON, 'Pauline'),
            default => null,
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $this->approverFor($scope, $resource, $owner) === $personId;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string
    {
        $actual = $this->ownerOf($scope, $resource);

        return $actual?->type === $owner->type && $actual->id === $owner->id ? $actual->id : null;
    }

    /** @return list<DiscoveryTarget> */
    public function discoveryTargets(): array
    {
        $scope = $this->current()->scope;

        return array_map(fn (ResourceReference $resource) => new DiscoveryTarget(
            $this->ownerOf($scope, $resource) ?? throw new InvalidArgumentException('Calendar owner missing.'),
            $resource,
        ), self::calendars());
    }

    /** @return list<ResourceReference> */
    private static function calendars(): array
    {
        return [new ResourceReference('calendar', 'tom-calendar'), new ResourceReference('calendar', 'pauline-calendar')];
    }

    private function calendarTool(string $name, string $action, string $effect): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            version: '1',
            actionKey: $action,
            inputSchema: [
                'type' => 'object',
                'properties' => ['date' => ['type' => 'string', 'format' => 'date'], 'reason' => ['type' => 'string']],
                'required' => ['date', 'reason'],
            ],
            normalizeArguments: static function (array $arguments): array {
                if ($arguments !== ['date' => self::DATE]) {
                    throw new InvalidArgumentException('Use the sample calendar date.');
                }

                return $arguments;
            },
            resolveTargets: self::calendars(...),
            executorId: self::class,
            effect: $effect,
            resultSchema: [
                'type' => 'object',
                'properties' => ['suggestions' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            approvalPreview: static fn (Invocation $call) => new ApprovalPreview(
                $call->reason,
                $effect.' for '.self::DATE,
            ),
        );
    }

    public function execute(Invocation $frozenCall, array $approvedTargets): ExecutionResult
    {
        $cache = Cache::store('database');
        $cache->increment('demo.executions');
        if ($frozenCall->toolName !== self::AVAILABILITY
            || $frozenCall->arguments !== ['date' => self::DATE]
            || array_column($approvedTargets, 'id') !== array_column(self::calendars(), 'id')) {
            return new ExecutionResult(ExecutionStatus::FAILED);
        }

        $suggestions = $this->jointSlots();
        $live = $this->forInvocation($frozenCall->context->scope, $frozenCall->id);
        if ($live === null || $live->audience !== $frozenCall->context->audience) {
            return new ExecutionResult(ExecutionStatus::FAILED);
        }

        $result = ['suggestions' => $suggestions];
        $cache->put('demo.model-result', $result);

        return new ExecutionResult(ExecutionStatus::SUCCEEDED, $result);
    }

    /** @return list<string> */
    private function jointSlots(): array
    {
        $slots = [['09:30', '10:00'], ['10:00', '10:30'], ['10:30', '11:00'], ['12:30', '13:00']];
        $tom = [['09:45', '10:39'], ['12:05', '13:05']];
        $pauline = [['09:52', '10:43'], ['13:09', '14:05']];
        $suggestions = [];
        foreach ($slots as [$start, $end]) {
            if (self::covers($tom, $start, $end) && self::covers($pauline, $start, $end)) {
                $suggestions[] = $start.'–'.$end;
            }
        }

        return $suggestions;
    }

    /** @param list<array{string, string}> $intervals */
    private static function covers(array $intervals, string $start, string $end): bool
    {
        foreach ($intervals as [$availableFrom, $availableUntil]) {
            if ($availableFrom <= $start && $availableUntil >= $end) {
                return true;
            }
        }

        return false;
    }
}
