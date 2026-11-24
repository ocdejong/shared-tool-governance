# Editable permissions

`Rules\PermissionTree` supplies the settings data for one resource. Laravel resolves it from the container using the shared `ToolRegistry` and `ResourceRules`. The host supplies the authenticated context, resource selection, routes, labels and controls. There is no separate settings catalog.

## Register once

Register a `ToolDefinition` in the host service provider, as shown in the [runnable calendar provider](../examples/permission-tree/PermissionTreeServiceProvider.php).

```php
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

$tools = app(ToolRegistry::class);
$tools->register(new ToolDefinition(
    name: 'reschedule',
    version: '1',
    actionKey: 'calendar.events.reschedule',
    inputSchema: $inputSchema,
    normalizeArguments: $normalizeArguments,
    resolveTargets: $resolveTargets,
    executorId: CalendarExecutor::class,
    effect: 'Reschedule the selected event',
    resultSchema: $resultSchema,
    approvalPreview: $approvalPreview,
));
```

The tree generates `calendar`, `calendar.events` and `calendar.events.reschedule`. Dispatch checks that same `actionKey` against every resource returned by `resolveTargets`. Multiple tool names or versions with one action key share one editable node. Each node's `tools` lists the names and versions registered at that exact key. Groups can also have tools and children. A parent setting governs descendants unless they have a closer explicit rule.

Keys remain literal and unchanged in storage. The tree includes all registered permissions, evaluated for the selected resource. It does not call `resolveTargets` or infer applicability from resource types, since targets can depend on invocation arguments. Stored rules for tools no longer registered remain in storage and are preserved by edits. They are absent from the tree until a registration uses their key or a descendant again.

## Read the tree

```php
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Rules\PermissionTree;

// The host derives $scope from its authenticated workspace context.
$resource = new ResourceReference('calendar', $calendarId);
$permissions = app(PermissionTree::class);
$snapshot = $permissions->read($scope, $resource);

return response()->json($snapshot);
```

`PermissionTreeSnapshot` exposes `revision`, `enabled` and a nested list of `nodes`. Nodes and tool references sort by key and by name/version respectively. A `PermissionNode` exposes:

| Field | Meaning |
| --- | --- |
| `key` | Full dotted permission key, also used when saving. |
| `explicitSetting` | `Verdict::ALLOW`, `DELEGATE`, `DENY`, or `null` for Use parent. |
| `effectiveVerdict` | The result of `RuleSettings::evaluate(key)`. |
| `source` | `PermissionSource::EXPLICIT`, `INHERITED`, `DEFAULT_DENY`, or `RESOURCE_OFF`. |
| `sourceKey` | The evaluator's matched rule key, or `null` for default deny and resource off. |
| `explanation` | The evaluator's explanation. |
| `tools` | List of `{name, version}` registrations at this key. Empty for generated groups. |
| `children` | Nested `PermissionNode` values. |

These public readonly values serialize directly to JSON. Backed enums serialize as strings: `ALLOW`, `DELEGATE`, `DENY`, and `explicit`, `inherited`, `default_deny`, `resource_off`.

The host can display `DELEGATE` as "Ask me" and `null` as "Use parent". For example, Reschedule can have `explicitSetting: null`, `effectiveVerdict: DELEGATE`, `source: inherited`, and `sourceKey: calendar`. Render that as "Inherit → Ask me, from Calendar". Default deny has no matching rule. Resource off overrides every rule while retaining each node's explicit setting, so turning access back on restores those settings.

## Save an override

```php
use Ocdejong\SharedToolGovernance\Data\Verdict;

$snapshot = $permissions->update($scope, $resource, $snapshot->revision, [
    'rules' => ['calendar' => Verdict::DELEGATE],
]);
$snapshot = $permissions->update($scope, $resource, $snapshot->revision, [
    'rules' => ['calendar.events.reschedule' => Verdict::ALLOW],
]);
// Use parent removes only this explicit rule.
$snapshot = $permissions->update($scope, $resource, $snapshot->revision, [
    'rules' => ['calendar.events.reschedule' => null],
]);
```

`update()` accepts the same patch as `ResourceRules::setRules()`: optional `rules` and optional `enabled`. Omitted keys stay unchanged, including explicit child overrides when a parent changes. Clearing a root rule falls back to default deny. To switch the resource off, send `['enabled' => false]` with the latest revision.

Both methods use `ResourceRules` management authorization. They reject another person's personal resource, an unauthorized manager, a mismatched owner binding, or a scope outside the authenticated workspace. A new resource starts at revision zero. Each successful edit advances the resource revision, shared by every node and the off switch. A stale revision raises `StaleRuleRevision` and saves nothing. The returned snapshot describes the revision just saved; another editor can subsequently create a newer revision.

In an HTTP host, validate input and convert verdict strings to `Verdict` values before calling `update()`. Map `RuleAccessDenied` to HTTP 403 and `StaleRuleRevision` to HTTP 409, then reload the tree. The package supplies no routes or frontend. This interface uses the package's default `ResourceRules` evaluator; hosts replacing `RuleEvaluator` must also provide a settings view consistent with their replacement policy.

## Dispatch the registered tool

```php
use Ocdejong\SharedToolGovernance\Governance;

$result = app(Governance::class)->call(
    $invocationId,
    'reschedule',
    '1',
    ['calendar' => $calendarId, 'time' => '14:30'],
    'Move the fixture visit.',
);
```

Dispatch checks the exact registered tool and resolved resources. With a valid trusted context and one target, `ALLOW` executes, `DELEGATE` returns `pending_approval`, and `DENY` refuses the call. Use a fresh invocation ID for a new request after an edit. Retrying the allowed request with its original ID and inputs reports its stored outcome with a null result and does not execute again. See [host integration](integration.md) for approval delivery and resumption contracts.

## Run Pauline's example

From this repository, after committing the changes:

```sh
bin/consumer-smoke --permission-tree HEAD
```

This installs the selected package revision into a disposable Laravel 13 consumer, migrates, caches config and runs the [example script](../examples/permission-tree/permission-tree.php). It prints Pauline's Calendar as Ask me, Availability as Allow, Events and Reschedule as inherited Ask me, and Delete as Deny. It then allows Reschedule and clears that override. Each edit is followed by a fresh dispatch. It checks the original allowed invocation's retry, one executor call, and Tom's unchanged settings and calendar. The smoke command also checks rollback and upgrade from the package seam. CI runs this with the two-owner demo using `--demo --permission-tree`.

To run it in your own disposable Laravel app after installing the package and running migrations, use a generated `APP_KEY`, SQLite, `CACHE_STORE=database` and `QUEUE_CONNECTION=database`:

```sh
cp vendor/ocdejong/shared-tool-governance/examples/permission-tree/PermissionTreeServiceProvider.php app/Providers/
cp vendor/ocdejong/shared-tool-governance/examples/permission-tree/permission-tree.php .
php permission-tree.php install
php artisan config:cache
php permission-tree.php
```

The example uses fixture identities, synthetic calendar state and a local private card adapter. Outbound HTTP is blocked. Pending requests stay pending; the sequence demonstrates settings and dispatch, without submitting approval replies. Real settings screens must bind `TrustedInvocationContext` to authenticated host state.
