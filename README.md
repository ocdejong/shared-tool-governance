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

## Smoke-test a clean consumer

From a Git checkout of this repository, run `bin/consumer-smoke` to test `HEAD`, or pass a commit with `bin/consumer-smoke <commit>`. You need Bash, Git, Composer, PHP with PDO SQLite and Composer access to Laravel's dependencies. The checkout must contain the seam commit `b4ceea1`.

The script creates a temporary Laravel 13 app with SQLite and the database queue. Composer installs the archived package revisions through a path repository. The script checks provider discovery, migration and rollback over seeded host data, and the upgrade from `b4ceea1` with existing governance data. It prints the runtime versions on success and removes the temporary app and databases on exit.

## License

[Apache License 2.0](LICENSE).
