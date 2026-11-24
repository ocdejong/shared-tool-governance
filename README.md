# Shared Tool Governance

A planned open-source PHP package for governing agent tool calls. Homies will install it with Composer and use its own database and queue workers.

The agent selects a tool and supplies a reason. The package checks the owners' rules, requests private approval when required, and records the scope granted and the execution outcome.

## Release order

1. Tool governance, with per-owner rules, discovery, approvals and receipts.
2. An owned secrets vault for credential use by authorized tools.
3. Browser governance for site-bound sign-in and approved actions during a session.

The package is in development.

## Install in a Laravel 13 app

Once the package is available on Packagist, run these commands from your Laravel application.

```sh
composer require ocdejong/shared-tool-governance
php artisan migrate
```

Laravel discovers the service provider and its migrations automatically. The migrations add `governance_*` tables to the application's database.

Follow the [host integration guide](docs/integration.md) to register tools, connect private approvals and run the queue worker. The [two-owner example](examples/two-owner) shows those bindings in a Laravel service provider.

## Optional configuration

The package uses your application's database, queue, scheduler and encryption key. To override the default durations, publish the config and edit `config/shared-tool-governance.php`.

```sh
php artisan vendor:publish --tag=shared-tool-governance-config
php artisan config:cache
```

- `grant_lifetime_minutes` defaults to 5.
- `approval_lifetime_minutes` defaults to 15.
- `retention_days` defaults to 30 and controls how long private inputs are retained after a call reaches a terminal state. If the cutoff falls before year 1, pruning succeeds with zero invocations.

Use whole numbers from 1 to 2,147,483,647 for these values. Published config can read them through Laravel's `env()` helper. Invalid values raise a configuration error when used. Changing the lifetimes only affects newly issued grants and approval requests. Register tool callbacks in PHP through the host's service provider so Laravel can cache the config.

## Smoke-test a clean consumer

From a Git checkout of this repository, run `bin/consumer-smoke` to test `HEAD`, or pass a commit with `bin/consumer-smoke <commit>`. You need Bash, Git, Composer, PHP with PDO SQLite and Composer access to Laravel's dependencies. The checkout must contain the seam commit `b4ceea1`.

The script creates a temporary Laravel 13 app with SQLite and the database queue. Composer installs the archived package revisions through a path repository. The script checks provider discovery, migration and rollback over seeded host data, and the upgrade from `b4ceea1` with existing governance data. It prints the runtime versions on success and removes the temporary app and databases on exit.

Run `bin/consumer-smoke --demo HEAD` to also run the two-owner example in that clean app. It discovers permissions for two calendars, delivers one private approval card, then starts a fresh queue worker to execute the approved call once and verify both owners' receipts. The example uses synthetic calendars and a local inbox and blocks outbound HTTP.

## License

[Apache License 2.0](LICENSE).
