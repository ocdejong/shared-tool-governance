<?php

declare(strict_types=1);

use App\Providers\PermissionTreeServiceProvider as Host;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\Rules\PermissionNode;
use Ocdejong\SharedToolGovernance\Rules\PermissionSource;
use Ocdejong\SharedToolGovernance\Rules\PermissionTree;
use Ocdejong\SharedToolGovernance\Rules\PermissionTreeSnapshot;

require __DIR__.'/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<PermissionNode> $nodes */
function showTree(array $nodes, int $depth = 0): void
{
    $labels = ['ALLOW' => 'Allow', 'DELEGATE' => 'Ask me', 'DENY' => 'Deny'];
    foreach ($nodes as $node) {
        $label = Str::headline(Str::afterLast($node->key, '.'));
        $setting = $node->explicitSetting === null ? 'Inherit' : $labels[$node->explicitSetting->value];
        $effective = $labels[$node->effectiveVerdict->value];
        echo str_repeat('  ', $depth).$label.'  '.$setting.' → '.$effective.' ['.$node->source->value;
        echo ($node->sourceKey === null ? '' : ', '.$node->sourceKey).']'.PHP_EOL;
        showTree($node->children, $depth + 1);
    }
}

/** @param list<PermissionNode> $nodes */
function findPermission(array $nodes, string $key): ?PermissionNode
{
    foreach ($nodes as $node) {
        if ($node->key === $key) {
            return $node;
        }
        $found = findPermission($node->children, $key);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

function dispatchMatches(PermissionTreeSnapshot $snapshot, string $id, PermissionSource $source): void
{
    $reschedule = findPermission($snapshot->nodes, 'calendar.events.reschedule') ?? throw new RuntimeException('Missing reschedule node.');
    check($reschedule->source === $source, 'Unexpected reschedule source.');
    $expectedStatus = match ($reschedule->effectiveVerdict) {
        Verdict::ALLOW => 'success',
        Verdict::DELEGATE => 'pending_approval',
        Verdict::DENY => 'denied',
    };
    Cache::put('permission-tree.request.'.$id, app(Host::class)->viewer);
    $result = app(Governance::class)->call($id, 'reschedule', '1', ['calendar' => 'pauline', 'time' => '14:30'], 'Move the fixture visit.');
    check($result['status'] === $expectedStatus, 'Dispatch disagrees with the displayed permission.');
    echo 'Dispatch: '.$result['status'].PHP_EOL.PHP_EOL;
}

if (($argv[1] ?? '') === 'install') {
    $path = __DIR__.'/bootstrap/providers.php';
    $providers = require $path;
    if (! in_array(Host::class, $providers, true)) {
        $providers[] = Host::class;
        check(file_put_contents($path, "<?php\n\nreturn ".var_export($providers, true).";\n") !== false, 'Could not register the example provider.');
    }
    exit(0);
}

try {
    $app = require __DIR__.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $host = app(Host::class);
    $tree = app(PermissionTree::class);
    $scope = $host->current()->scope;
    $pauline = new ResourceReference('calendar', 'pauline');
    $tom = new ResourceReference('calendar', 'tom');
    $run = (string) Str::uuid();

    $host->viewer = 'tom';
    $other = $tree->read($scope, $tom);
    $other = $tree->update($scope, $tom, $other->revision, ['rules' => ['calendar' => Verdict::ALLOW]]);
    $host->viewer = 'pauline';

    $snapshot = $tree->read($scope, $pauline);
    $snapshot = $tree->update($scope, $pauline, $snapshot->revision, ['enabled' => true, 'rules' => [
        'calendar' => Verdict::DELEGATE,
        'calendar.availability' => Verdict::ALLOW,
        'calendar.events' => null,
        'calendar.events.delete' => Verdict::DENY,
        'calendar.events.reschedule' => null,
    ]]);
    showTree($snapshot->nodes);
    dispatchMatches($snapshot, $run.'-ask', PermissionSource::INHERITED);
    check($host->executions === 0, 'Asking for approval must not run the executor.');

    $snapshot = $tree->update($scope, $pauline, $snapshot->revision, ['rules' => ['calendar.events.reschedule' => Verdict::ALLOW]]);
    showTree($snapshot->nodes);
    dispatchMatches($snapshot, $run.'-allow', PermissionSource::EXPLICIT);
    $retry = app(Governance::class)->call($run.'-allow', 'reschedule', '1', ['calendar' => 'pauline', 'time' => '14:30'], 'Move the fixture visit.');
    check($retry['status'] === 'success' && $retry['result'] === null && $host->executions === 1, 'The retry must report success without executing again.');

    $snapshot = $tree->update($scope, $pauline, $snapshot->revision, ['rules' => ['calendar.events.reschedule' => null]]);
    showTree($snapshot->nodes);
    dispatchMatches($snapshot, $run.'-inherit', PermissionSource::INHERITED);
    check($host->executions === 1 && $host->times === ['pauline' => '14:30', 'tom' => '10:00'], 'Only the allowed call may change the selected calendar.');

    $host->viewer = 'tom';
    check(json_encode($tree->read($scope, $tom), JSON_THROW_ON_ERROR) === json_encode($other, JSON_THROW_ON_ERROR), 'The other owner permission tree changed.');
    echo 'Permission tree example passed: Ask me → Allow → Use parent; one execution; Tom unchanged.'.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
