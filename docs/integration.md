# Integrate with a Laravel host

[Install the package and run its migrations](../README.md#install-in-a-laravel-13-app). The package uses the host's default database connection and Laravel `APP_KEY` for encrypted governance data. Share the host's `APP_KEY` and `app.timezone` configuration across web, queue and scheduler processes.

SQLite and PostgreSQL are covered by the repository's integration checks. The database clock code also has branches for MySQL, MariaDB and SQL Server. Those drivers are outside the current test matrix. Grant deadlines use database time. See [recent use and retention](retention.md) for the migration order on an existing governance installation.

## Register the host services

Bind these services in a Laravel service provider. Package class names below are relative to `Ocdejong\SharedToolGovernance`. The [two-owner service provider](../examples/two-owner/TwoOwnerServiceProvider.php) shows the wiring.

| Service | What the host supplies |
| --- | --- |
| `Contracts\ResourceAuthority` | Resolve current household membership and resource ownership, who can manage each owner's rules, and who may approve for that owner. |
| `Contracts\TrustedInvocationContext` | Return an authenticated `Data\InvocationContext` from `current()`, with the host and household scope, requester, runtime and intended audience. Derive these values from host state. |
| `Contracts\ResumptionContext` | Implement `forInvocation($scope, $invocationId)` to recover current trusted context from a persisted host request. Return `null` if it is gone or no longer valid. This must work in a fresh worker process. |
| `Contracts\ApprovalNotifier` | Implement `deliver($scope, $deliveryId, $recipientId, $message)` over a private channel. Preserve the delivery ID as the approval ID and return the same nonempty card ID on retries. |
| `Tools\ToolRegistry` | Bind a populated registry. Call `register($tool)` for each `Data\ToolDefinition` and look up definitions by name and version with `get($name, $version)`. |
| `Contracts\ToolExecutor` | Bind each executor under its tool's `executorId` in the Laravel container. `execute($frozenCall, $approvedTargets)` receives the normalized arguments and approved targets. Treat the reason as audit data. Check the intended audience before delivering results. |
| `Contracts\CompletionHandler` | Optional. Receive the scope, invocation ID and execution result for a delegated call in the queue worker. |
| `Discovery\ToolDiscovery` | Bind it with the trusted context, resource authority, `Contracts\RuleEvaluator`, the registered tool definitions and a host visibility callback. The package binds `RuleEvaluator` to `Rules\ResourceRules` by default. |

In each tool definition, publish the argument schema, implement argument normalization and resolve targets from the normalized arguments and trusted context. Keep the schema and normalizer in agreement. The host can expose the schema with its tool catalog. Discovery returns permission information. Supply an approval preview for a tool whose owners can delegate, so approvers can see the reason, scope and intended effect.

Use `ResourceRules::getRules($scope, $resource)` and `setRules($scope, $resource, $expectedRevision, $changes)` from authenticated settings code. Rules inherit along dotted action keys such as `calendar` and `calendar.availability`. Missing permission defaults to `DENY`.

Call `ToolDiscovery::discover($scope, $targets, $mode, $namespace)` with host-selected `Discovery\DiscoveryTarget` values. `Discovery\DiscoveryMode::ALL` reports visible permissions and unresolved actions. `DiscoveryMode::MATCH` returns actions with an `ALLOW` or `DELEGATE` verdict for every selected target. Run authorization for the actual call even after a discovery match.

## Run a governed call

1. Build a `Data\Invocation` in the normal host dispatcher. Give it a stable invocation ID, the selected tool name and version, its arguments, a nonblank human-readable reason, the trusted context and the registered executor ID. Keep the reason in the invocation. Persist enough host request state for the resumption adapter to resolve this invocation later.
2. Call `Authorization\Authorizer::authorize($invocation)`. The package checks ownership and rules for the selected targets and freezes the call and its audience. A direct grant returns a token through `Authorization\AuthorizationResult::grantToken()` on initial authorization. Keep it in host code and out of logs, model responses and messages. Repeating the same call ID does not issue another token. An ID already bound to different inputs raises `Authorization\InvocationConflict`.
3. For a direct grant, call `Grants\GrantService::redeem($token, $invocation)` with the same call and current trusted context, outside an open database transaction. The package rechecks policy and consumes the grant before invoking the registered executor. Treat `Grants\RedemptionRefused` as a stop for that call.
4. For `pending_approval`, the package queues private cards and returns the request ID. In the host's reply handler, authenticate the sender and verify that the reply belongs to the specific card and its intended recipient. Construct `Data\VerifiedReply` only then, from the trusted scope, sender ID, card ID and unique reply ID. Pass it to `Approvals\Approvals::decide($approvalId, $decision, $reply)` with `Approvals\ApprovalDecision::APPROVE` or `DECLINE`. The host verifies the channel event. The package checks the reply against its delivery record. After all required owners approve, the queued worker rechecks authority, issues and redeems the grant, and calls the executor.
5. Return `Data\ExecutionResult` from the executor. The package records the outcome and returns the direct result to the caller. If the downstream result is uncertain, record it as unknown and reconcile with the provider before retrying the operation. Call `GrantService::recordOutcome($invocationId, $outcome, $providerReference)` as the original requester and runtime to resolve an unknown outcome on a redeemed call. The provider reference is optional.

## Deliver delegated results

To deliver delegated results, bind `Contracts\CompletionHandler` in the host's service provider. Implement `completed(Scope $scope, string $invocationId, ExecutionResult $result): void`. The package records the execution outcome before dispatching a separate encrypted Laravel job with the scope, ID and result. Return queue-serializable result data from the executor.

Look up the original request by scope and ID in the handler and recheck the current audience and access before disclosing the result. Deliver privately and use the scope and invocation ID to deduplicate delivery. Throw on a retryable delivery failure so the queue can retry the handler. An executor exception yields an `unknown` outcome with an empty result. If the worker stops before the completion job reaches the queue, the receipt remains available. The receipt records the outcome and cannot reconstruct the result payload.

The [two-owner example](../examples/two-owner/TwoOwnerServiceProvider.php) writes one local inbox entry per invocation after checking the request's audience.

## Read status and outcome

Use `Approvals::receipt($invocationId)` for the requester's status and outcome in the same runtime. It returns `null` when the invocation is absent or unavailable to the requester in that runtime. Use `Activity\Activity::recentUse($invocationId)` for authenticated participant receipts and `Activity::revoke($invocationId)` to cancel eligible calls. See [recent use and retention](retention.md) for access and retention details.

| Status | Meaning |
| --- | --- |
| `denied` | Policy refused the call. |
| `pending_approval` | One or more required approvals are outstanding. |
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
