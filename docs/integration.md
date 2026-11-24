# Integrate with a Laravel host

[Install the package and run its migrations](../README.md#install-in-a-laravel-13-app). The package uses the host's default database connection and Laravel `APP_KEY` for encrypted governance data. Share the host's `APP_KEY` and `app.timezone` configuration across web, queue and scheduler processes.

SQLite and PostgreSQL are covered by the repository's integration checks. The database clock code also has branches for MySQL, MariaDB and SQL Server. Those drivers are outside the current test matrix. Grant deadlines use database time. See [recent use and retention](retention.md) for the migration order on an existing governance installation.

## Register the host services

Bind the services in a Laravel service provider. Package class names below are relative to `Ocdejong\SharedToolGovernance`. The [appointment example](../examples/appointment/AppointmentServiceProvider.php) and [two-owner example](../examples/two-owner/TwoOwnerServiceProvider.php) show the bindings.

| Service | What the host supplies |
| --- | --- |
| `Contracts\ResourceAuthority` | Resolve workspace membership, resource ownership and who can manage the owner's rules. For a personal owner, the default approver is that owner. |
| `Contracts\TrustedInvocationContext` | Return an authenticated `Data\InvocationContext` from `current()` with the host scope, workspace, requester, runtime and intended audience. Derive these values from trusted host state. |
| `Contracts\ToolExecutor` | Bind each executor by its tool's `executorId`. `execute($frozenCall, $approvedTargets)` receives normalized arguments and approved targets. Treat the reason as audit data. |
| `Contracts\ApproverPolicy` | Optional. Return an `ApprovalRule` with eligible people and an `ApprovalMode` for each owner requirement. Return `null` to use `ResourceAuthority::approverFor()`. |
| `Contracts\ApprovalNotifier` | For private approval, deliver the message only to the designated person. Preserve the delivery ID as the approval ID and return the same nonempty card ID on retries. |
| `Contracts\GroupApprovalChannel` | Optional. For an `any` requirement, select an authorized chat and supply a preview safe for everyone in it. Return `null` for private delivery. Recheck disclosure in `allowsDisclosure()` at delivery and return the original card ID on retries. |
| `Contracts\ResumptionContext` | For delegation, implement `forInvocation($scope, $invocationId)` to recover current trusted context from a persisted host request in a fresh worker. Return `null` if the request is gone or invalid. |
| `Contracts\CompletionHandler` | Optional. Receive the scope, invocation ID and execution result for a delegated call. |

Use `Scope::$workspaceId` and `OwnerType::WORKSPACE` in new host code. `Scope::$householdId`, the serialized `householdId` field, the `HOUSEHOLD` enum case and household storage names remain for compatibility with stored state and queued jobs.

Register each `Data\ToolDefinition` with the shared `Tools\ToolRegistry`. Keep the argument schema and normalizer in agreement. The definition resolves targets from normalized arguments and trusted context and supplies an approval preview for private delegation. The package binds discovery to the same registry and uses `Rules\ResourceRules` as its default rule evaluator. The default discovery visibility exposes only the requester's own personal resources in a private audience. Bind `Discovery\ToolDiscovery` with a host visibility callback when the host can safely expose other resources.

Use `ResourceRules::getRules($scope, $resource)` and `setRules($scope, $resource, $expectedRevision, $changes)` from authenticated settings code. Rules inherit along dotted action keys such as `calendar` and `calendar.availability`. Missing permission defaults to `DENY`.

For an editable tree derived from those same tool registrations, use `Rules\PermissionTree::read($scope, $resource)` and `update($scope, $resource, $expectedRevision, $changes)`. The [permission tree guide](permission-tree.md) documents the node fields, inherited verdict sources, override patches and runnable calendar example.

Call `ToolDiscovery::discover($scope, $targets, $mode, $namespace)` with host-selected `Discovery\DiscoveryTarget` values. `DiscoveryMode::ALL` reports visible permissions and unresolved actions. `DiscoveryMode::MATCH` returns actions with an `ALLOW` or `DELEGATE` verdict for every selected target. Dispatch still checks the exact call after a discovery match.

## Run a governed call

Use `Governance::call($id, $tool, $version, $arguments, $reason)` outside an open database transaction. Supply a stable invocation ID and a nonblank human-readable reason. Persist the host request before dispatch if it can require approval. The return value includes `status`, `call_id` and `result`. A direct execution returns its outcome and result. On a repeat, the same call ID reports the stored outcome or current authorization status with a null result. Reusing an ID with changed inputs raises `Authorization\InvocationConflict`.

Hosts that compose the lower-level APIs can build a `Data\Invocation`, call `Authorization\Authorizer::authorize()` and redeem a direct grant through `Grants\GrantService::redeem($token, $invocation)`. Keep the token in host code, outside logs, model responses and messages. Redeem outside an open transaction. The package checks current policy before consuming a grant. Treat `Grants\RedemptionRefused` as a stop for that call.

Return `Data\ExecutionResult` from the executor. If the downstream outcome is uncertain, record it as `unknown` and reconcile with the provider before retrying the operation. The original requester in the same runtime can use `GrantService::recordOutcome($invocationId, $outcome, $providerReference)` to reconcile a redeemed call. The provider reference is optional.

## Approve a delegated call

For a deliverable `pending_approval` request, the package stages cards atomically. Each independent owner's requirement must pass. In `any`, the first eligible person's approve or decline decides that requirement. In `all`, each eligible person must approve and a decline ends the call. The package freezes the mode, eligible voters, exact call and intended audience. A person can vote for only one requirement within a call. When the call names Olivier and Pauline separately, each must consent for themselves. Combined legacy cards expire without execution.

Authenticate the human sender and verify the reply is to the original delivered card in the correct private thread or group chat. Build `Data\VerifiedReply($scope, $senderId, $cardId, $replyId, $chatId)` from verified channel data. Supply the chat ID for group replies. The delivery ID from the notifier or group adapter is the approval ID. Pass it with the reply and `ApprovalDecision::APPROVE` or `DECLINE` to `Approvals::respond()`.

`ApprovalResponse::status` is `recorded`, `settled` or `refused`. For `settled`, `decision` reports the settled requirement even if the incoming reply arrived too late. Check `accepted` to tell whether the reply was accepted, including an exact duplicate of an accepted reply. Existing private callers can use `Approvals::decide()` for the boolean alone. A receipt reports the status of the whole call.

Group chat authorization to see the preview and the list of people allowed to vote are independent. A chat observer may see the approved preview. Keep private reasons, targets, audience IDs and results out of group messages. The [local CLI adapter](../examples/appointment/LocalApprovalChannel.php) shows private and group replies with synthetic authenticated identities. A production adapter must verify actual channel events before creating a verified reply. After approval the worker rechecks current eligibility and audience before execution. Lost authority can revoke the call.

### Approval diagnostics

| Condition | Host behavior | Requester behavior |
| --- | --- | --- |
| A private preview callback is missing, returns null or produces a blank reason or scope/change. A group prompt with a blank chat ID or preview is also a setup error. | Catch `Approvals\ApprovalSetupError` and use its `invocationId` to diagnose the tool or channel configuration. Staging rolls back. | The host can report a generic tool error. The package issues no grant and makes no change. |
| An owner is unreachable during staging, for example when there is no eligible approver. | Listen for the after-commit `Approvals\ApprovalUndeliverable` event, scoped by `scope` and `invocationId`. Its `reason` is null in this case. Zero cards are staged. | The privacy-safe response is `pending_approval` until expiry. |
| A recorded vote leaves no distinct assignment of eligible people that can complete the call. | The after-commit `ApprovalUndeliverable` event has reason `distinct_completion_impossible`. The package issues zero grants. | Both the receipt and reauthorization return terminal `approval_undeliverable`. |

A transient notifier failure on a staged request can be retried with the same delivery ID.

## Deliver delegated results

To deliver delegated results, bind `Contracts\CompletionHandler` in the host's service provider. Implement `completed(Scope $scope, string $invocationId, ExecutionResult $result): void`. The package records the execution outcome before dispatching a separate encrypted Laravel job with the scope, ID and result. Return queue-serializable result data from the executor.

Look up the original request by scope and ID in the handler and recheck the current audience and access before disclosing the result. Deliver privately and use the scope and invocation ID to deduplicate delivery. Throw on a retryable delivery failure so the queue can retry the handler. An executor exception yields an `unknown` outcome with an empty result. If the worker stops before the completion job reaches the queue, the receipt remains available. The receipt records the outcome and cannot reconstruct the result payload.

The [two-owner example](../examples/two-owner/TwoOwnerServiceProvider.php) writes one local inbox entry per invocation after checking the request's audience.

## Read status and outcome

Use `Approvals::receipt($invocationId)` for the requester's status and outcome in the same runtime. It returns `null` when the invocation is absent or unavailable to the requester in that runtime. Use `Activity\Activity::recentUse($invocationId)` for authenticated participant receipts and `Activity::revoke($invocationId)` to cancel eligible calls. See [recent use and retention](retention.md) for access and retention details.

| Status | Meaning |
| --- | --- |
| `denied` | Policy refused the call. |
| `pending_approval` | Required approvals are outstanding, or the call could not stage any cards and is awaiting expiry. |
| `approval_undeliverable` | A recorded vote made distinct completion impossible. The call is terminal. |
| `approved` | All required approvals arrived. The worker still needs to resume the call. |
| `declined` | A required approver declined. |
| `expired` | The approval window closed before the call could proceed. |
| `revoked` | The call was revoked before redemption or lost the authority it needed to proceed. |
| `approved_unused` | An issued grant went unused, for example because it expired before redemption. |
| `granted` | A grant was issued. A redeemed grant also has an execution outcome. |

The `outcome` field is separate. The values are `success` (`Data\ExecutionStatus::SUCCEEDED`), `failure` (`ExecutionStatus::FAILED`) and `unknown` (`ExecutionStatus::UNKNOWN`). A grant can be redeemed and still show `unknown` if the process lost the downstream result. A grant waiting to be redeemed has no outcome yet.

## Run the queue and scheduler

Configure a persistent Laravel queue such as the database queue. Run `php artisan queue:work` against the configured connection and queue so it can deliver pending cards, resume approved calls and deliver delegated results. Run Laravel's scheduler with `schedule:run` every minute or a long-running `php artisan schedule:work`. The package registers approval recovery every minute and the `shared-tool-governance:prune` command daily.

From a package checkout, run `bin/consumer-smoke --demo HEAD` to see these steps in a clean Laravel app with a fresh worker process.

## Verify the 0.1 release candidate

Use a full SHA for the integrated revision that will be tagged. On that revision, collect both CI job links, SQLite and PostgreSQL. Keep the two-owner demo and existing-database upgrade result from `bin/consumer-smoke --demo <full-sha>` with the install evidence.

```sh
bin/dist-install <full-sha>
bin/dist-install --github <full-sha>
```

The local run builds and tests a versioned Composer ZIP from the selected Git revision and prints its SHA-256 and path. To rebuild it, run the same command from a checkout containing that revision and record the checksum of the ZIP produced by the new run. The GitHub run verifies the ZIP fetched for the pushed SHA using temporary Composer metadata. Both run the appointment README in a fresh app with cached config. The consumer installs the package into `vendor/` without a package checkout, path repository or Git history.

Track 01 owns the pre-tag migration fold and the existing-database check for that fold. The planner records the evidence in Track 01 and refreshes the SHA, CI links, install results and checksum if cleanup, rebase or merge changes the revision. This example uses a local fixture channel. Hosting an authenticated channel or a production appointment integration is the host's responsibility. MySQL, MariaDB and SQL Server remain outside the tested database scope.

Once Olivier publishes 0.1, verify resolution from Packagist and check that the installed ZIP matches the release commit.

```sh
bin/dist-install --packagist <published-version> <tag-or-commit>
```
