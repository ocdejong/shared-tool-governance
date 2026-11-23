# Shared Tool Governance

A planned open-source PHP package for governing agent tool calls. Homies will install it with Composer and use its own database and queue workers.

The agent selects a tool and supplies a reason. The package checks the owners' rules, requests private approval when required, and records the scope granted and the execution outcome.

## Release order

1. Tool governance, with per-owner rules, discovery, approvals and receipts.
2. An owned secrets vault for credential use by authorized tools.
3. Browser governance for site-bound sign-in and approved actions during a session.

The repository is at the planning stage.

## License

[Apache License 2.0](LICENSE).
