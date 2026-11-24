<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Contracts\ToolExecutor;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryAccess;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryMode;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryTarget;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Rules\RuleAccessDenied;
use Ocdejong\SharedToolGovernance\Tests\Rules\RulesHost;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
});

function calendarTarget(string $person, bool $resolved = true): DiscoveryTarget
{
    return new DiscoveryTarget(
        new OwnerReference(OwnerType::PERSON, $person),
        $resolved ? new ResourceReference('calendar', $person, accountAlias: 'private alias') : null,
    );
}

function discoveryTool(string $action): ToolDefinition
{
    return new ToolDefinition(
        name: $action,
        version: '1',
        actionKey: $action,
        inputSchema: [],
        normalizeArguments: static fn () => throw new LogicException('Discovery must not normalize an invocation.'),
        resolveTargets: static fn () => throw new LogicException('Discovery must not resolve invocation targets.'),
        executorId: 'fixture',
        effect: 'Read calendar',
        resultSchema: [],
    );
}

it('shows availability with both verdicts in MATCH and includes visible denials in ALL', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $rules = new ResourceRules($host, $host);
    $tom = calendarTarget('tom');
    $pauline = calendarTarget('pauline');
    $lee = calendarTarget('lee');
    foreach ([
        ['tom', Verdict::ALLOW, Verdict::ALLOW],
        ['pauline', Verdict::DELEGATE, Verdict::DENY],
        ['lee', Verdict::ALLOW, Verdict::DELEGATE],
    ] as [$owner, $availability, $details]) {
        $host->requester = $owner;
        $rules->setRules($scope, new ResourceReference('calendar', $owner), 0, ['rules' => [
            'calendar.availability' => $availability,
            'calendar.details' => $details,
            'calendar.unregistered' => Verdict::ALLOW,
        ]]);
    }
    $host->requester = 'tom';
    $this->mock(ToolExecutor::class)->shouldNotReceive('execute');
    $query = new ToolDiscovery($host, $host, $rules, [
        discoveryTool('calendar.availability'),
        discoveryTool('calendar.details'),
        discoveryTool('calendar-import.events'),
    ], static fn () => DiscoveryAccess::VISIBLE);

    $match = $query->discover($scope, [$tom, $pauline], DiscoveryMode::MATCH, 'calendar');
    $all = $query->discover($scope, [$tom, $pauline, $lee], namespace: 'calendar');

    expect(array_keys($match->actions))->toBe(['calendar.availability'])
        ->and(array_column($match->actions['calendar.availability'], 'verdict'))->toBe([Verdict::ALLOW, Verdict::DELEGATE])
        ->and(array_column($match->actions['calendar.availability'], 'owner'))->toEqual([$tom->owner, $pauline->owner])
        ->and(array_keys($all->actions))->toBe(['calendar.availability', 'calendar.details'])
        ->and(array_column($all->actions['calendar.details'], 'verdict'))->toBe([Verdict::ALLOW, Verdict::DENY, Verdict::DELEGATE])
        ->and(json_encode($all, JSON_THROW_ON_ERROR))->not->toContain('private alias');

    foreach (['invocations', 'approvals', 'grants', 'outbox_messages', 'audit_events'] as $table) {
        expect(DB::table('governance_'.$table)->count())->toBe(0);
    }
});

it('omits hidden permissions and reports unresolved accounts or arguments', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $rules = new ResourceRules($host, $host);
    $rules->setRules($scope, new ResourceReference('calendar', 'tom'), 0, ['rules' => ['calendar' => Verdict::ALLOW]]);

    $query = new ToolDiscovery($host, $host, $rules, [
        discoveryTool('calendar.availability'),
        discoveryTool('calendar.details'),
        discoveryTool('calendar.events'),
    ], static function (InvocationContext $context, string $action, DiscoveryTarget $target): DiscoveryAccess {
        if ($target->owner->id === 'pauline' && $action === 'calendar.details' && in_array('tom', $context->audience, true)) {
            return DiscoveryAccess::HIDDEN;
        }

        return $action === 'calendar.events' ? DiscoveryAccess::UNRESOLVED : DiscoveryAccess::VISIBLE;
    });

    $targets = [calendarTarget('tom'), calendarTarget('pauline', false)];
    $all = $query->discover($scope, $targets);
    $match = $query->discover($scope, $targets, DiscoveryMode::MATCH);

    expect(array_column($all->actions['calendar.availability'], 'verdict'))->toBe([Verdict::ALLOW, null])
        ->and(array_column($all->actions['calendar.details'], 'verdict'))->toBe([Verdict::ALLOW])
        ->and(array_column($all->actions['calendar.events'], 'verdict'))->toBe([null, null])
        ->and($match->actions)->toBe([])
        ->and($match->unresolved)->toBe(['calendar.availability', 'calendar.events']);
});

it('rejects discovery outside the verified household, tenant, or ownership', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $query = new ToolDiscovery($host, $host, new ResourceRules($host, $host), [discoveryTool('calendar.availability')],
        static fn () => throw new LogicException('Check scope and ownership before asking about visibility.'));

    expect(fn () => $query->discover(new Scope('tenant', 'other-home'), [calendarTarget('tom')]))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $query->discover(new Scope('another-tenant', 'home'), [calendarTarget('tom')]))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $query->discover($scope, [new DiscoveryTarget(
            new OwnerReference(OwnerType::PERSON, 'tom'),
            new ResourceReference('calendar', 'pauline'),
        )]))->toThrow(RuleAccessDenied::class);

    $host->members = ['tom', 'lee'];
    expect(fn () => $query->discover($scope, [calendarTarget('pauline', false)]))->toThrow(RuleAccessDenied::class);
});
