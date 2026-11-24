<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Authorization\InvocationConflict;
use Ocdejong\SharedToolGovernance\Contracts\ApprovalNotifier;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\ResumptionContext;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryMode;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryTarget;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tests\Rules\RulesHost;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
});

function appointmentTool(ResourceReference $calendar): ToolDefinition
{
    return new ToolDefinition(
        name: 'reschedule_appointment',
        version: '1',
        actionKey: 'calendar.reschedule',
        inputSchema: [
            'type' => 'object',
            'properties' => ['time' => ['type' => 'string']],
            'required' => ['time'],
            'additionalProperties' => false,
        ],
        normalizeArguments: static function (array $arguments): array {
            if (! is_string($arguments['time'] ?? null) || count($arguments) !== 1) {
                throw new InvalidArgumentException('Expected only the requested time.');
            }

            return ['time' => trim($arguments['time'])];
        },
        resolveTargets: static fn () => [$calendar],
        executorId: ToolExecutor::class,
        effect: 'Move the appointment to the requested time',
        resultSchema: ['type' => 'object', 'properties' => ['time' => ['type' => 'string']]],
    );
}

it('discovers and dispatches one registration with the default Laravel bindings', function (): void {
    $host = new RulesHost;
    $host->audience = ['tom'];
    app()->instance(TrustedInvocationContext::class, $host);
    app()->instance(ResourceAuthority::class, $host);
    $scope = $host->current()->scope;
    $calendar = new ResourceReference('calendar', 'tom');
    $target = new DiscoveryTarget(new OwnerReference(OwnerType::PERSON, 'tom'), $calendar);
    $discovery = app(ToolDiscovery::class);
    $governance = app(Governance::class);
    $registry = app(ToolRegistry::class);
    expect(app(ToolRegistry::class))->toBe($registry)
        ->and($discovery->discover($scope, [$target])->actions)->toBe([])
        ->and(app()->bound(ApprovalNotifier::class))->toBeFalse()
        ->and(app()->bound(ResumptionContext::class))->toBeFalse();

    $tool = appointmentTool($calendar);
    $registry->register($tool);
    $all = $discovery->discover($scope, [$target]);
    expect(array_column($all->actions[$tool->actionKey], 'verdict'))->toBe([Verdict::DENY])
        ->and($discovery->discover($scope, [$target], DiscoveryMode::MATCH)->actions)->toBe([]);

    $arguments = ['time' => ' 14:30 '];
    $reason = 'Move the repair visit';
    $denied = $governance->call('appointment-denied', $tool->name, $tool->version, $arguments, $reason);
    expect($denied)->toBe(['status' => 'denied', 'call_id' => 'appointment-denied', 'result' => null])
        ->and(DB::table('governance_grants')->count())->toBe(0);

    app(ResourceRules::class)->setRules($scope, $calendar, 0, ['rules' => [$tool->actionKey => Verdict::ALLOW]]);
    $match = $discovery->discover($scope, [$target], DiscoveryMode::MATCH);
    expect(array_column($match->actions[$tool->actionKey], 'verdict'))->toBe([Verdict::ALLOW])
        ->and($governance->call('appointment-denied', $tool->name, $tool->version, $arguments, $reason))->toBe($denied);

    $this->mock(ToolExecutor::class)->shouldReceive('execute')->once()
        ->withArgs(static function (Invocation $call, array $targets) use ($calendar, $reason): bool {
            return $call->id === 'appointment-allowed'
                && $call->arguments === ['time' => '14:30']
                && $call->reason === $reason
                && $targets === [$calendar]
                && DB::transactionLevel() === 0;
        })->andReturn(new ExecutionResult(ExecutionStatus::SUCCEEDED, ['time' => '14:30'], 'private-provider-id'));

    expect($governance->call('appointment-allowed', $tool->name, $tool->version, $arguments, $reason))
        ->toBe(['status' => 'success', 'call_id' => 'appointment-allowed', 'result' => ['time' => '14:30']])
        ->and($governance->call('appointment-allowed', $tool->name, $tool->version, ['time' => '14:30'], $reason))
        ->toBe(['status' => 'granted', 'call_id' => 'appointment-allowed', 'result' => null])
        ->and(fn () => $governance->call('appointment-allowed', $tool->name, $tool->version, ['time' => '15:00'], $reason))
        ->toThrow(InvocationConflict::class)
        ->and(DB::table('governance_invocations')->count())->toBe(2)
        ->and(DB::table('governance_grants')->whereNotNull('redeemed_at')->count())->toBe(1)
        ->and(DB::table('governance_outbox_messages')->count())->toBe(0);
});

it('keeps discovery private by default and allows the host to supply the registry', function (): void {
    $host = new RulesHost;
    app()->instance(TrustedInvocationContext::class, $host);
    app()->instance(ResourceAuthority::class, $host);
    $calendar = new ResourceReference('calendar', 'tom');
    $target = new DiscoveryTarget(new OwnerReference(OwnerType::PERSON, 'tom'), $calendar);
    $scope = $host->current()->scope;
    $tool = appointmentTool($calendar);
    $registry = new ToolRegistry;
    $registry->register($tool);
    app()->instance(ToolRegistry::class, $registry);

    $discovery = app(ToolDiscovery::class);
    expect($discovery->discover($scope, [$target])->actions)->toBe([]);

    $host->audience = ['tom'];
    expect(array_keys($discovery->discover($scope, [$target])->actions))->toBe([$tool->actionKey])
        ->and(app(Governance::class)->call('host-registry', $tool->name, $tool->version, ['time' => '14:30'], 'Move the visit'))
        ->toBe(['status' => 'denied', 'call_id' => 'host-registry', 'result' => null]);
});

it('refuses to start a call inside a transaction before it can issue a grant', function (): void {
    $host = new RulesHost;
    app()->instance(TrustedInvocationContext::class, $host);
    app()->instance(ResourceAuthority::class, $host);
    $calendar = new ResourceReference('calendar', 'tom');
    $tool = appointmentTool($calendar);
    app(ToolRegistry::class)->register($tool);
    app(ResourceRules::class)->setRules($host->current()->scope, $calendar, 0, ['rules' => [$tool->actionKey => Verdict::ALLOW]]);
    $governance = app(Governance::class);

    DB::transaction(static function () use ($governance, $tool): void {
        expect(fn () => $governance->call('inside-transaction', $tool->name, $tool->version, ['time' => '14:30'], 'Move the visit'))
            ->toThrow(DomainException::class);
    });

    expect(DB::table('governance_invocations')->count())->toBe(0)
        ->and(DB::table('governance_grants')->count())->toBe(0);
});
