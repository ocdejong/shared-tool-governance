# Shared Tool Governance

Govern an agent's request to move a customer's appointment. Register the reschedule tool once. The package discovers it, checks the exact change against the owner's rules and records the execution outcome. A rule can allow the change directly or require human approval. Missing permission defaults to deny.

## Install in a Laravel 13 app

The package targets PHP 8.3+ and Laravel 13. The repository tests SQLite and PostgreSQL. The Composer package is awaiting its first public release.

After publication, run the Composer command from the root of a fresh Laravel 13 app.

<!-- appointment-install -->
```sh
composer require ocdejong/shared-tool-governance
```

Laravel discovers the package provider automatically. Run the migrations to create the `governance_*` tables.

```sh
php artisan migrate
```
<!-- /appointment-install -->

## Run the appointment example

Use a disposable Laravel app with a generated `APP_KEY`, SQLite, `CACHE_STORE=database` and `QUEUE_CONNECTION=database`. The installed package includes the [appointment service provider](examples/appointment/AppointmentServiceProvider.php) and the [local approval channel](examples/appointment/LocalApprovalChannel.php). The provider registers one `ToolDefinition` with `ToolRegistry::register()`. It describes the input, resolves the appointment, selects the executor and builds the private preview.

Copy the example into the app and register its provider. Then cache config and run the first calls.

<!-- appointment-example -->
```sh
cp vendor/ocdejong/shared-tool-governance/examples/appointment/AppointmentServiceProvider.php app/Providers/
cp vendor/ocdejong/shared-tool-governance/examples/appointment/LocalApprovalChannel.php app/Providers/
cp vendor/ocdejong/shared-tool-governance/examples/appointment/appointment.php .
php appointment.php install
php artisan config:cache
php appointment.php prepare
```

The [example dispatcher](examples/appointment/appointment.php) calls the registered tool through `Governance`.

```php
use Ocdejong\SharedToolGovernance\Governance;

$result = app(Governance::class)->call(
    'first-reschedule',
    'reschedule',
    '1',
    ['appointment' => 'olivier-slot', 'time' => '2026-11-24T15:00:00Z'],
    'Move the fixture appointment.',
);
```

`prepare` checks default denial, sets an explicit allow, discovers the tool and dispatches the call above once. It then switches to delegation and queues approval cards. Run one worker to deliver the cards, submit fixture replies, then start a fresh worker to resume calls and deliver results.

```sh
php artisan queue:work --stop-when-empty --sleep=0 --tries=1
php appointment.php reply
php artisan queue:work --stop-when-empty --sleep=0 --tries=1
php appointment.php verify
```
<!-- /appointment-example -->

Pauline or Olivier can settle the team's `any` rule in the shared chat. A late reply reports the first decision. In the second group request, Olivier declines and Pauline's later approval leaves the call declined. With two named owners, Olivier's private approval leaves Pauline's own decision outstanding. A separate `all` rule waits for both private approvals. The fresh worker executes each approved call once and delivers each result to the scheduler's local private inbox.

The example uses fixture identities and a database-backed local inbox, with outbound HTTP blocked. Use your app's authenticated context and channel verification for real requests. See the [host integration guide](docs/integration.md) for those contracts.

## Optional configuration

The package uses your application's database, queue, scheduler and encryption key. To override the default durations, publish the config and edit `config/shared-tool-governance.php`.

<!-- appointment-config -->
```sh
php artisan vendor:publish --tag=shared-tool-governance-config
php artisan config:cache
```
<!-- /appointment-config -->

- `grant_lifetime_minutes` defaults to 5.
- `approval_lifetime_minutes` defaults to 15.
- `retention_days` defaults to 30 and controls how long private inputs are retained after a call reaches a terminal state. If the cutoff falls before year 1, pruning succeeds with zero invocations.

Use whole numbers from 1 to 2,147,483,647 for these values. Published config can read them through Laravel's `env()` helper. Invalid values raise a configuration error when used. Changing the lifetimes only affects newly issued grants and approval requests. Register tool callbacks in PHP through the host's service provider so Laravel can cache the config.

## Smoke-test a clean consumer

From a Git checkout, run `bin/dist-install HEAD` to build a versioned Composer ZIP, install it into a fresh Laravel app and run the README sequence from the installed package. The script prints the tested ZIP's SHA-256 and path. To test GitHub's ZIP for a pushed commit, run `bin/dist-install --github <full-sha>`.

From a Git checkout of this repository, run `bin/consumer-smoke` to test `HEAD`, or pass a commit with `bin/consumer-smoke <commit>`. You need Bash, Git, Composer, PHP with PDO SQLite and Composer access to Laravel's dependencies. The checkout must contain the seam commit `b4ceea1`.

The script creates a temporary Laravel 13 app with SQLite and the database queue. Composer installs the archived package revisions through a path repository. The script checks provider discovery, migration and rollback over seeded host data, and the upgrade from `b4ceea1` with existing governance data. It prints the runtime versions on success and removes the temporary app and databases on exit.

Run `bin/consumer-smoke --demo HEAD` to also run the two-owner example in that clean app. It discovers permissions for two calendars, delivers one private approval card, then starts a fresh queue worker to execute the approved call once and verify both owners' receipts. The example uses synthetic calendars and a local inbox and blocks outbound HTTP.

## License

[Apache License 2.0](LICENSE).
