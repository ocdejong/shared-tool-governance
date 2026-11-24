# Editable permissions

`Rules\PermissionTree` supplies the settings data for one resource. Laravel resolves it from the container using the shared `ToolRegistry`, `ResourceRules` and configured `RuleEvaluator`. The host supplies the authenticated context, resource selection, routes, labels and controls. There is no separate settings catalog.

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

Numeric paths are supported too. Registering `365.calendar` generates the editable parent `365`. The root `0` is also valid. PHP can convert numeric dictionary keys to integers, so the package restores strings before processing paths and stores overrides as a JSON object. Node `key` and matched `sourceKey` values remain literal strings in JSON. Existing stored keys keep their meaning and inheritance.

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

`PermissionTreeSnapshot` exposes the saved settings' `revision`, `enabled` flag and a nested list of `nodes`. A custom policy's own revision does not replace the saved revision used for optimistic editing. Nodes sort by literal string key, and tool references sort by name/version. A `PermissionNode` exposes:

| Field | Meaning |
| --- | --- |
| `key` | Full dotted permission key, also used when saving. |
| `explicitSetting` | `Verdict::ALLOW`, `DELEGATE`, `DENY`, or `null` for Use parent. |
| `effectiveVerdict` | The verdict from the active `RuleEvaluator` for this scope, resource, verified owner and action key. |
| `source` | `PermissionSource::EXPLICIT`, `INHERITED`, `DEFAULT_DENY`, `RESOURCE_OFF`, or `HOST_POLICY`. |
| `sourceKey` | The active evaluator's matched rule key, or `null` when it supplies none. |
| `explanation` | The evaluator's explanation. |
| `tools` | List of `{name, version}` registrations at this key. Empty for generated groups. |
| `children` | Nested `PermissionNode` values. |

These public readonly values serialize directly to JSON. Backed enums serialize as strings: `ALLOW`, `DELEGATE`, `DENY`, and `explicit`, `inherited`, `default_deny`, `resource_off`, `host_policy`.

The host can display `DELEGATE` as "Ask me" and `null` as "Use parent". For example, Reschedule can have `explicitSetting: null`, `effectiveVerdict: DELEGATE`, `source: inherited`, and `sourceKey: calendar`. Render that as "Inherit → Ask me, from Calendar". With the built-in evaluator, default deny has no matching rule. Resource off overrides every rule while retaining each node's explicit setting, so turning access back on restores those settings.

## Saved settings and effective policy

`ResourceRules` owns management authorization, stored overrides, the enabled flag and revision checks. The `RuleEvaluator` bound in Laravel owns the effective verdict, explanation and matched key. Both `read()` and the snapshot returned by `update()` use that policy for the same verified owner, scope, resource and action that dispatch evaluates. Reading settings does not authorize an invocation, issue a grant or execute a tool.

For example, a host can bind its own evaluator in a service provider:

```php
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;

$this->app->bind(RuleEvaluator::class, CalendarPolicy::class);
```

`CalendarPolicy` implements the existing `RuleEvaluator` contract. If Pauline saves Allow for Reschedule but the host policy returns Deny, the node shows `explicitSetting: ALLOW` and `effectiveVerdict: DENY` with the host's explanation. A custom evaluator can also allow an action without naming a matched rule. Either verdict with a null matched key has source `host_policy`; a missing key alone does not mean default deny. A matched key equal to the node gives source `explicit`, and a different matched key gives `inherited`.

The saved enabled flag remains visible with a custom evaluator. That evaluator decides how saved settings, including the off switch, affect its verdict, just as it does during dispatch. The tree does not override the host result or infer `resource_off` from the saved flag. Implement custom evaluators as policy reads and propagate failures so an edit can roll back.

The built-in `ResourceRules` evaluator evaluates the single settings snapshot already loaded or saved by the operation. It preserves the `explicit`, `inherited`, `default_deny` and `resource_off` sources without loading settings again for each node.

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

`update()` accepts the same patch as `ResourceRules::setRules()`: optional `rules` and optional `enabled`. Omitted keys stay unchanged, including explicit child overrides when a parent changes. With the built-in policy, clearing a root rule falls back to default deny. To switch the resource off, send `['enabled' => false]` with the latest revision.

Both methods use `ResourceRules` management authorization, including when a custom evaluator allows access. They reject another person's personal resource, an unauthorized manager, a mismatched owner binding, or a scope outside the authenticated workspace. A new resource starts at revision zero. Each successful edit advances the resource revision once, shared by every node and the off switch. A stale revision raises `StaleRuleRevision` and saves nothing. The edit and its returned snapshot share a transaction: if validation, policy evaluation or snapshot construction fails, the previous settings and revision remain intact. The returned snapshot describes the revision just saved; another editor can subsequently create a newer revision.

In an HTTP host, validate input and convert verdict strings to `Verdict` values before calling `update()`. Map `RuleAccessDenied` to HTTP 403 and `StaleRuleRevision` to HTTP 409, then reload the tree. The package supplies no routes or frontend. Hosts replacing `RuleEvaluator` can use the same tree interface to display their effective policy alongside saved settings.

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
