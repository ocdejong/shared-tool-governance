<?php

declare(strict_types=1);

use App\Providers\PermissionTreeServiceProvider as Host;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\Rules\PermissionNode;
use Ocdejong\SharedToolGovernance\Rules\PermissionSource;
use Ocdejong\SharedToolGovernance\Rules\PermissionTree;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Rules\RuleAccessDenied;
use Ocdejong\SharedToolGovernance\Rules\StaleRuleRevision;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

require_once __DIR__.'/../../examples/permission-tree/PermissionTreeServiceProvider.php';

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    app()->register(Host::class);
});

/**
 * @param  list<PermissionNode>  $nodes
 * @return array<array-key, PermissionNode>
 */
function permissionNodes(array $nodes): array
{
    $indexed = [];
    foreach ($nodes as $node) {
        $indexed[$node->key] = $node;
        $indexed += permissionNodes($node->children);
    }

    return $indexed;
}

function registerPermission(string $name, string $version, string $action): void
{
    $registry = app(ToolRegistry::class);
    $original = $registry->get('reschedule', '1');
    $registry->register(new ToolDefinition(
        $name, $version, $action, $original->inputSchema, $original->normalizeArguments,
        $original->resolveTargets, $original->executorId, $original->effect, $original->resultSchema,
        $original->approvalPreview,
    ));
}

it('returns the editable calendar tree with the evaluator verdict and source for every node', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class)->update($scope, $resource, 0, ['rules' => [
        'calendar' => Verdict::DELEGATE,
        'calendar.availability' => Verdict::ALLOW,
        'calendar.events.delete' => Verdict::DENY,
    ]]);
    $nodes = permissionNodes($tree->nodes);

    expect($tree->revision)->toBe(1)
        ->and($tree->enabled)->toBeTrue()
        ->and(array_keys($nodes))->toBe(['calendar', 'calendar.availability', 'calendar.events', 'calendar.events.delete', 'calendar.events.reschedule'])
        ->and($nodes['calendar']->explicitSetting)->toBe(Verdict::DELEGATE)
        ->and($nodes['calendar']->source)->toBe(PermissionSource::EXPLICIT)
        ->and($nodes['calendar.availability']->explicitSetting)->toBe(Verdict::ALLOW)
        ->and($nodes['calendar.events']->explicitSetting)->toBeNull()
        ->and($nodes['calendar.events']->source)->toBe(PermissionSource::INHERITED)
        ->and($nodes['calendar.events']->sourceKey)->toBe('calendar')
        ->and($nodes['calendar.events.reschedule']->explicitSetting)->toBeNull()
        ->and($nodes['calendar.events.reschedule']->source)->toBe(PermissionSource::INHERITED)
        ->and($nodes['calendar.events.reschedule']->sourceKey)->toBe('calendar')
        ->and($nodes['calendar.events.delete']->explicitSetting)->toBe(Verdict::DENY)
        ->and($nodes['calendar.events.delete']->source)->toBe(PermissionSource::EXPLICIT);

    $owner = app(Host::class)->ownerOf($scope, $resource) ?? throw new LogicException('The fixture calendar must have an owner.');
    foreach ($nodes as $node) {
        $evaluation = app(ResourceRules::class)->evaluate($scope, $resource, $owner, $node->key);
        expect($node->effectiveVerdict)->toBe($evaluation->verdict)
            ->and($node->sourceKey)->toBe($evaluation->matchedActionKey)
            ->and($node->explanation)->toBe($evaluation->explanation);
    }

    $json = json_decode(json_encode($tree, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($json['nodes'][0]['explicitSetting'])->toBe('DELEGATE')
        ->and($json['nodes'][0]['children'][1]['source'])->toBe('inherited');
});

it('groups shared keys across names and versions and sees registrations added after resolving the tree service', function (): void {
    $tree = app(PermissionTree::class);
    registerPermission('reschedule', '2', 'calendar.events.reschedule');
    registerPermission('move_visit', '1', 'calendar.events.reschedule');
    registerPermission('events', '1', 'calendar.events');
    registerPermission('email', '1', 'email.send');

    $snapshot = $tree->read(app(Host::class)->current()->scope, new ResourceReference('calendar', 'pauline'));
    $nodes = permissionNodes($snapshot->nodes);
    expect(array_column($snapshot->nodes, 'key'))->toBe(['calendar', 'email'])
        ->and($nodes['calendar']->tools)->toBe([])
        ->and($nodes['calendar.events']->tools)->toBe([['name' => 'events', 'version' => '1']])
        ->and($nodes['calendar.events.reschedule']->tools)->toBe([
            ['name' => 'move_visit', 'version' => '1'],
            ['name' => 'reschedule', 'version' => '1'],
            ['name' => 'reschedule', 'version' => '2'],
        ])
        ->and($nodes['email.send']->effectiveVerdict)->toBe(Verdict::DENY);

    $updated = $tree->update(app(Host::class)->current()->scope, new ResourceReference('calendar', 'pauline'), 0, [
        'rules' => ['calendar.events.reschedule' => Verdict::ALLOW],
    ]);
    expect(permissionNodes($updated->nodes)['calendar.events.reschedule']->effectiveVerdict)->toBe(Verdict::ALLOW)
        ->and(app(ResourceRules::class)->getRules(app(Host::class)->current()->scope, new ResourceReference('calendar', 'pauline'))->rules)
        ->toBe(['calendar.events.reschedule' => Verdict::ALLOW]);
});

it('matches dispatch after Ask me, Allow and Use parent and executes the allowed invocation only once', function (): void {
    $host = app(Host::class);
    $scope = $host->current()->scope;
    $tree = app(PermissionTree::class);
    $pauline = new ResourceReference('calendar', 'pauline');
    $tom = new ResourceReference('calendar', 'tom');
    $host->viewer = 'tom';
    $other = $tree->update($scope, $tom, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);
    $host->viewer = 'pauline';
    $governance = app(Governance::class);
    $call = function (string $id) use ($governance, $host): array {
        Cache::put('permission-tree.request.'.$id, $host->viewer);

        return $governance->call($id, 'reschedule', '1', ['calendar' => 'pauline', 'time' => '14:30'], 'Move the visit.');
    };

    $snapshot = $tree->update($scope, $pauline, 0, ['rules' => ['calendar' => Verdict::DELEGATE]]);
    $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
    expect($node->effectiveVerdict)->toBe(Verdict::DELEGATE)
        ->and($node->sourceKey)->toBe('calendar')
        ->and($call('ask')['status'])->toBe('pending_approval')
        ->and($host->executions)->toBe(0);

    $snapshot = $tree->update($scope, $pauline, $snapshot->revision, ['rules' => ['calendar.events.reschedule' => Verdict::ALLOW]]);
    $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
    expect($node->effectiveVerdict)->toBe(Verdict::ALLOW)
        ->and($node->source)->toBe(PermissionSource::EXPLICIT)
        ->and($call('allow'))->toBe(['status' => 'success', 'call_id' => 'allow', 'result' => ['time' => '14:30']])
        ->and($call('allow'))->toBe(['status' => 'success', 'call_id' => 'allow', 'result' => null])
        ->and($host->executions)->toBe(1);

    $snapshot = $tree->update($scope, $pauline, $snapshot->revision, ['rules' => ['calendar.events.reschedule' => null]]);
    $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
    expect($node->explicitSetting)->toBeNull()
        ->and($node->effectiveVerdict)->toBe(Verdict::DELEGATE)
        ->and($node->source)->toBe(PermissionSource::INHERITED)
        ->and($node->sourceKey)->toBe('calendar')
        ->and($call('inherit')['status'])->toBe('pending_approval')
        ->and($call('allow')['status'])->toBe('success')
        ->and($host->executions)->toBe(1)
        ->and($host->times)->toBe(['pauline' => '14:30', 'tom' => '10:00'])
        ->and(DB::table('governance_grants')->whereNotNull('redeemed_at')->count())->toBe(1);

    $host->viewer = 'tom';
    expect($tree->read($scope, $tom))->toEqual($other);
});

it('preserves explicit children and stored keys while editing and clearing a parent', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);
    $tree->update($scope, $resource, 0, ['rules' => [
        'calendar.events' => Verdict::ALLOW,
        'calendar.events.delete' => Verdict::DENY,
        'legacy.permission' => Verdict::DELEGATE,
    ]]);
    $snapshot = $tree->update($scope, $resource, 1, ['rules' => ['calendar' => Verdict::DELEGATE]]);
    $nodes = permissionNodes($snapshot->nodes);
    expect($nodes['calendar.events.reschedule']->effectiveVerdict)->toBe(Verdict::ALLOW)
        ->and($nodes['calendar.events.reschedule']->sourceKey)->toBe('calendar.events')
        ->and($nodes['calendar.events.delete']->effectiveVerdict)->toBe(Verdict::DENY);

    $snapshot = $tree->update($scope, $resource, 2, ['rules' => ['calendar.events' => null]]);
    $nodes = permissionNodes($snapshot->nodes);
    expect($nodes['calendar.events.reschedule']->effectiveVerdict)->toBe(Verdict::DELEGATE)
        ->and($nodes['calendar.events.delete']->effectiveVerdict)->toBe(Verdict::DENY);

    $snapshot = $tree->update($scope, $resource, 3, ['rules' => ['calendar' => null]]);
    $nodes = permissionNodes($snapshot->nodes);
    expect($nodes['calendar']->source)->toBe(PermissionSource::DEFAULT_DENY)
        ->and($nodes['calendar.events.delete']->explicitSetting)->toBe(Verdict::DENY)
        ->and(app(ResourceRules::class)->getRules($scope, $resource)->rules)->toBe([
            'calendar.events.delete' => Verdict::DENY,
            'legacy.permission' => Verdict::DELEGATE,
        ]);
});

it('distinguishes default denial from the resource off switch and agrees with dispatch', function (bool $off): void {
    $host = app(Host::class);
    $scope = $host->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);
    $snapshot = $tree->read($scope, $resource);
    expect($snapshot->revision)->toBe(0);
    if ($off) {
        $snapshot = $tree->update($scope, $resource, 0, ['enabled' => false, 'rules' => ['calendar.events.reschedule' => Verdict::ALLOW]]);
    }
    $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
    expect($node->explicitSetting)->toBe($off ? Verdict::ALLOW : null)
        ->and($node->effectiveVerdict)->toBe(Verdict::DENY)
        ->and($node->source)->toBe($off ? PermissionSource::RESOURCE_OFF : PermissionSource::DEFAULT_DENY)
        ->and($node->sourceKey)->toBeNull()
        ->and(app(Governance::class)->call('deny', 'reschedule', '1', ['calendar' => 'pauline', 'time' => '14:30'], 'Move the visit.')['status'])->toBe('denied')
        ->and($host->executions)->toBe(0);

    if ($off) {
        $restored = $tree->update($scope, $resource, $snapshot->revision, ['enabled' => true]);
        expect(permissionNodes($restored->nodes)['calendar.events.reschedule']->effectiveVerdict)->toBe(Verdict::ALLOW);
    }
})->with([false, true]);

it('rejects stale edits without changing the saved tree', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);
    $saved = $tree->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::DELEGATE]]);

    expect(fn () => $tree->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]))->toThrow(StaleRuleRevision::class)
        ->and($tree->read($scope, $resource))->toEqual($saved)
        ->and(DB::table('governance_rule_revisions')->count())->toBe(1);
});

it('requires resource management authority and the authenticated workspace for reads and edits', function (string $viewer, string $hostScope, string $workspace): void {
    app(Host::class)->viewer = $viewer;
    $scope = new Scope($hostScope, $workspace);
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);

    expect(fn () => $tree->read($scope, $resource))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $tree->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]))->toThrow(RuleAccessDenied::class)
        ->and(DB::table('governance_rule_revisions')->count())->toBe(0);
})->with([
    'peer owner' => ['tom', 'permission-tree-example', 'home'],
    'nonmember' => ['stranger', 'permission-tree-example', 'home'],
    'other workspace' => ['pauline', 'permission-tree-example', 'elsewhere'],
    'other host' => ['pauline', 'elsewhere', 'home'],
]);

it('refuses a stored resource binding that no longer belongs to the verified owner', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);
    $tree->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);
    DB::table('governance_resource_bindings')->update(['owner_id' => 'tom']);

    expect(fn () => $tree->read($scope, $resource))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $tree->update($scope, $resource, 1, ['enabled' => false]))->toThrow(RuleAccessDenied::class);
});

it('isolates the same resource and owner when the authenticated workspace changes', function (): void {
    $host = app(Host::class);
    $tree = app(PermissionTree::class);
    $resource = new ResourceReference('calendar', 'pauline');
    $original = $tree->update($host->current()->scope, $resource, 0, ['rules' => ['calendar' => Verdict::DELEGATE]]);

    $host->workspace = 'other-home';
    $elsewhere = $tree->read($host->current()->scope, $resource);
    expect($elsewhere->revision)->toBe(0)
        ->and(permissionNodes($elsewhere->nodes)['calendar']->source)->toBe(PermissionSource::DEFAULT_DENY);
    $tree->update($host->current()->scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);

    $host->workspace = 'home';
    expect($tree->read($host->current()->scope, $resource))->toEqual($original);
});

it('returns an empty tree when no tools are registered', function (): void {
    app()->instance(ToolRegistry::class, new ToolRegistry);
    $snapshot = app(PermissionTree::class)->read(app(Host::class)->current()->scope, new ResourceReference('calendar', 'pauline'));
    expect($snapshot->nodes)->toBe([])->and($snapshot->revision)->toBe(0)->and($snapshot->enabled)->toBeTrue();
});

it('uses the configured policy for both saved and freshly read snapshots and dispatch', function (Verdict $saved, Verdict $effective, string $status): void {
    $host = app(Host::class);
    $scope = $host->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $owner = new OwnerReference(OwnerType::PERSON, 'pauline');
    app(PermissionTree::class)->update($scope, $resource, 0, ['rules' => ['calendar.events.reschedule' => $saved]]);
    $this->mock(RuleEvaluator::class)->shouldReceive('evaluate')
        ->withArgs(static fn (Scope $actualScope, ResourceReference $actualResource, OwnerReference $actualOwner, string $action): bool => $actualScope->equals($scope) && $actualResource == $resource && $actualOwner == $owner
            && in_array($action, ['calendar', 'calendar.availability', 'calendar.events', 'calendar.events.delete', 'calendar.events.reschedule'], true))
        ->andReturn(new RuleEvaluation($effective, null, 999, 'Host policy decides this permission.'));

    $tree = app(PermissionTree::class);
    $updated = $tree->update($scope, $resource, 1, ['rules' => ['calendar.events.reschedule' => $saved]]);
    foreach ([$updated, $tree->read($scope, $resource)] as $snapshot) {
        $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
        expect($snapshot->revision)->toBe(2)
            ->and($node->explicitSetting)->toBe($saved)
            ->and($node->effectiveVerdict)->toBe($effective)
            ->and($node->explanation)->toBe('Host policy decides this permission.')
            ->and($node->sourceKey)->toBeNull()
            ->and($node->source->value)->toBe('host_policy');
    }
    expect(DB::table('governance_invocations')->count())->toBe(0)
        ->and(DB::table('governance_grants')->count())->toBe(0)
        ->and($host->executions)->toBe(0);

    expect(app(Governance::class)->call('host-policy', 'reschedule', '1', ['calendar' => 'pauline', 'time' => '14:30'], 'Move the visit.')['status'])->toBe($status)
        ->and($host->executions)->toBe($effective === Verdict::ALLOW ? 1 : 0);
})->with([
    'saved allow and host deny' => [Verdict::ALLOW, Verdict::DENY, 'denied'],
    'saved deny and host allow without a matched key' => [Verdict::DENY, Verdict::ALLOW, 'success'],
]);

it('reads a numeric permission parent as a literal string', function (): void {
    registerPermission('numeric_calendar', '1', '365.calendar');
    $snapshot = app(PermissionTree::class)->read(app(Host::class)->current()->scope, new ResourceReference('calendar', 'pauline'));
    $parent = permissionNodes($snapshot->nodes)['365'];
    expect($parent->key)->toBe('365')->and($parent->children[0]->key)->toBe('365.calendar');
});

it('saves a numeric parent rule through resource management', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    app(ResourceRules::class)->setRules($scope, $resource, 0, ['rules' => ['365' => Verdict::DELEGATE]]);
    $reloaded = app(ResourceRules::class)->getRules($scope, $resource);
    expect($reloaded->revision)->toBe(1)
        ->and($reloaded->evaluate('365.calendar')->verdict)->toBe(Verdict::DELEGATE);
});

it('round trips numeric paths through tree edits, storage, inheritance and JSON', function (string $parent): void {
    $child = $parent.'.calendar';
    registerPermission('numeric_calendar', '1', $child);
    if ($parent === '0') {
        registerPermission('zero_root', '1', '0');
    }
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $snapshot = app(PermissionTree::class)->read($scope, $resource);
    $nodes = permissionNodes($snapshot->nodes);
    expect($snapshot->revision)->toBe(0)
        ->and($nodes[$parent]->key)->toBe($parent)
        ->and($nodes[$child]->key)->toBe($child);

    $edits = [
        [['rules' => [$parent => Verdict::DELEGATE]], Verdict::DELEGATE, PermissionSource::INHERITED, $parent],
        [['rules' => [$child => Verdict::ALLOW]], Verdict::ALLOW, PermissionSource::EXPLICIT, $child],
        [['rules' => [$child => null]], Verdict::DELEGATE, PermissionSource::INHERITED, $parent],
        [['rules' => [$parent => null]], Verdict::DENY, PermissionSource::DEFAULT_DENY, null],
    ];
    foreach ($edits as $index => [$changes, $verdict, $source, $sourceKey]) {
        $saved = app(PermissionTree::class)->update($scope, $resource, $snapshot->revision, $changes);
        $snapshot = app(PermissionTree::class)->read($scope, $resource);
        $nodes = permissionNodes($snapshot->nodes);
        expect($snapshot)->toEqual($saved)
            ->and($snapshot->revision)->toBe($index + 1)
            ->and($nodes[$parent]->key)->toBe($parent)
            ->and($nodes[$child]->effectiveVerdict)->toBe($verdict)
            ->and($nodes[$child]->source)->toBe($source)
            ->and($nodes[$child]->sourceKey)->toBe($sourceKey)
            ->and(DB::table('governance_rule_revisions')->count())->toBe($index + 1);

        $json = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $jsonParent = array_values(array_filter($json['nodes'], static fn (array $node): bool => $node['key'] === $parent))[0];
        expect($jsonParent['key'])->toBe($parent)
            ->and($jsonParent['children'][0]['key'])->toBe($child);

        if ($index === 0) {
            $stored = DB::table('governance_rule_revisions')->orderByDesc('revision')->value('rules');
            expect($stored)->toBeString()->toContain('"'.$parent.'"');
        }
    }

    expect(fn () => app(PermissionTree::class)->update($scope, $resource, $snapshot->revision, ['rules' => [$parent => Verdict::ALLOW, 'bad..key' => Verdict::DENY]]))
        ->toThrow(InvalidArgumentException::class)
        ->and(app(PermissionTree::class)->read($scope, $resource))->toEqual($snapshot)
        ->and(app(ResourceRules::class)->getRules($scope, $resource)->rules)->toBe([])
        ->and(DB::table('governance_rule_revisions')->count())->toBe(4);
})->with(['365', '0']);

it('rolls back the edit if the configured policy cannot build its returned snapshot', function (bool $existing): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    if ($existing) {
        app(PermissionTree::class)->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);
    }
    $previous = app(PermissionTree::class)->read($scope, $resource);
    $this->mock(RuleEvaluator::class)->shouldReceive('evaluate')->once()->andThrow(new RuntimeException('Host policy unavailable.'));

    expect(fn () => app(PermissionTree::class)->update($scope, $resource, $previous->revision, ['rules' => ['calendar' => Verdict::DENY]]))
        ->toThrow(RuntimeException::class, 'Host policy unavailable.');

    app()->instance(RuleEvaluator::class, app(ResourceRules::class));
    expect(app(PermissionTree::class)->read($scope, $resource))->toEqual($previous)
        ->and(DB::table('governance_rule_revisions')->count())->toBe($existing ? 1 : 0)
        ->and(DB::table('governance_resource_bindings')->count())->toBe($existing ? 1 : 0);
})->with([false, true]);

it('keeps custom policy results behind resource management authorization', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    app(Host::class)->viewer = 'tom';
    $this->mock(RuleEvaluator::class)->shouldNotReceive('evaluate');

    expect(fn () => app(PermissionTree::class)->read($scope, $resource))->toThrow(RuleAccessDenied::class)
        ->and(fn () => app(PermissionTree::class)->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]))->toThrow(RuleAccessDenied::class)
        ->and(DB::table('governance_rule_revisions')->count())->toBe(0);
});

it('uses the custom matched key to identify sources independently of the saved off switch', function (?string $matched, PermissionSource $source): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $this->mock(RuleEvaluator::class)->shouldReceive('evaluate')->andReturn(new RuleEvaluation(Verdict::ALLOW, $matched, null, 'Host policy allows this resource.'));
    $tree = app(PermissionTree::class);
    $updated = $tree->update($scope, $resource, 0, ['enabled' => false]);

    foreach ([$updated, $tree->read($scope, $resource)] as $snapshot) {
        $node = permissionNodes($snapshot->nodes)['calendar.events.reschedule'];
        expect($snapshot->enabled)->toBeFalse()
            ->and($node->explicitSetting)->toBeNull()
            ->and($node->effectiveVerdict)->toBe(Verdict::ALLOW)
            ->and($node->source)->toBe($source)
            ->and($node->sourceKey)->toBe($matched);
    }
})->with([
    'exact policy key' => ['calendar.events.reschedule', PermissionSource::EXPLICIT],
    'ancestor policy key' => ['calendar', PermissionSource::INHERITED],
    'no policy key' => [null, PermissionSource::HOST_POLICY],
]);

it('loads the built in settings once per read or update regardless of the number of nodes', function (): void {
    $scope = app(Host::class)->current()->scope;
    $resource = new ResourceReference('calendar', 'pauline');
    $tree = app(PermissionTree::class);
    $tree->update($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);
    $reads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'governance_rule_revisions')) {
            $reads++;
        }
    });

    $tree->read($scope, $resource);
    expect($reads)->toBe(1);
    $tree->update($scope, $resource, 1, ['rules' => ['calendar' => Verdict::DELEGATE]]);
    expect($reads)->toBe(2);
});
