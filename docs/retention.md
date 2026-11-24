# Recent use and retention

Use `Ocdejong\SharedToolGovernance\Activity\Activity` from authenticated host code. The package gets the viewer and household from `TrustedInvocationContext` and checks current membership. Keep these operations out of the model-facing tool catalog.

- `recentUse($invocationId = null)` returns up to 50 visible receipts, newest first. Pass an invocation ID to look up a specific call. Receipts show who requested the call, its status and the recorded execution outcome, when available. The requester sees their stated reason while the encrypted frozen inputs remain and the identity, decision and timestamp of each approver who answered. Owners and authorized managers receive the invocation ID, action, request timestamp, rule details and decided approvals for the targets they can manage. The package checks that the current owner matches the owner recorded at authorization.
- `revoke($invocationId)` returns true when the requester, an owner, or an authorized manager cancels a pending, approved, or unredeemed call. Revocation applies to the whole call, including multi-owner calls. A grant that was already redeemed cannot be revoked.

An unexpired, unredeemed grant reads as `granted` without an outcome. An expired unused grant reads as `approved_unused`. Once a grant is redeemed, its outcome is `unknown` until the executor records a known result. Reconcile uncertain results through the host before attempting the operation again.

## Upgrading a live installation

Fresh installations can run the full migration set. For an existing installation with active governance traffic, apply the changes in this order.

1. Run the nullable-column migration using the new package files while the previous application still serves traffic. From the Laravel application, run `php artisan migrate --path=vendor/ocdejong/shared-tool-governance/database/migrations/2026_11_24_000002_allow_encrypted_invocation_reasons.php`.
2. Hand governance requests and approval queues to the new application and workers together. Keep pending jobs in the queue for the new workers. The new code can resume calls from either storage format. Start using its authorizer once the previous workers have stopped consuming, since those workers can only resume calls with a plaintext reason.
3. Run `php artisan migrate` once the previous application and workers have stopped using governance. The remaining migration copies retained reasons into encrypted frozen inputs and drops the legacy column. Reasons on already-pruned calls are discarded.

Rolling back the drop restores retained reasons to a nullable plaintext column, including reasons for calls that are still pending. Already-pruned reasons stay deleted. The new runtime continues to work with the restored schema. If you also roll back the application, keep approval processing on compatible workers until the older runtime takes over authorization and any calls created during the rollback have a restored reason or have reached a terminal state.

## Pruning

The Laravel scheduler runs `php artisan shared-tool-governance:prune` daily when the host runs `schedule:run` or `schedule:work`. You can also run the command manually.

Pruning clears private approval previews, outbound card payloads, and frozen execution inputs, including the requester's reason, by default 30 days after a terminal decision, an expiry, or a known execution outcome. The approval workflow can clear its private card content earlier. Calls with unknown outcomes, including redeemed calls awaiting an outcome, keep their remaining inputs for reconciliation. The retention period starts when the known outcome is recorded. The [optional package config](../README.md#optional-configuration) defines the retention period in days.

The package keeps status, outcome, requester identity, approval decisions, audit events, and encrypted target metadata needed to filter receipts after pruning. Receipts omit grant material, raw arguments, private card text, reply and card identifiers, and provider references. Pruning covers package database rows. The host controls retention for delivered messages, backups, and any external copies.
