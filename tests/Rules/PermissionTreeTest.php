<?php

declare(strict_types=1);

use App\Providers\PermissionTreeServiceProvider as Host;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
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
 * @return array<string, PermissionNode>
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
