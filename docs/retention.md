# Recent use and retention

Use `Ocdejong\SharedToolGovernance\Activity\Activity` from authenticated host code. The package gets the viewer and household from `TrustedInvocationContext` and checks current membership. Keep these operations out of the model-facing tool catalog.

- `recentUse($invocationId = null)` returns up to 50 visible receipts, newest first. Pass an invocation ID to look up a specific call. Receipts show who requested the call, its status and the recorded execution outcome, when available. The requester sees their stated reason while the encrypted frozen inputs remain and the identity, decision and timestamp of each approver who answered. Owners and authorized managers receive the invocation ID, action, request timestamp, rule details and decided approvals for the targets they can manage. The package checks that the current owner matches the owner recorded at authorization.
- `revoke($invocationId)` returns true when the requester, an owner, or an authorized manager cancels a pending, approved, or unredeemed call. Revocation applies to the whole call, including multi-owner calls. A grant that was already redeemed cannot be revoked.

An unexpired, unredeemed grant reads as `granted` without an outcome. An expired unused grant reads as `approved_unused`. Once a grant is redeemed, its outcome is `unknown` until the executor records a known result. Reconcile uncertain results through the host before attempting the operation again.

## Pruning

The package migration moves existing reasons into encrypted frozen inputs and discards reasons for calls already pruned. Rolling back restores retained reasons to a nullable plaintext column.

The Laravel scheduler runs `php artisan shared-tool-governance:prune` daily when the host runs `schedule:run` or `schedule:work`. You can also run the command manually.

Pruning clears private approval previews, outbound card payloads, and frozen execution inputs, including the requester's reason, 30 days after a terminal decision, an expiry, or a known execution outcome. The approval workflow can clear its private card content earlier. Calls with unknown outcomes, including redeemed calls awaiting an outcome, keep their remaining inputs for reconciliation. The 30-day clock starts when the known outcome is recorded.

The package keeps status, outcome, requester identity, approval decisions, audit events, and encrypted target metadata needed to filter receipts after pruning. Receipts omit grant material, raw arguments, private card text, reply and card identifiers, and provider references. Pruning covers package database rows. The host controls retention for delivered messages, backups, and any external copies.
