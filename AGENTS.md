# Shared Tool Governance

The package supports PHP 8.3+ and Laravel 13. Develop with PHP 8.4+ for Pest 5 and enable PDO SQLite. Enable PDO PostgreSQL for database-specific checks.

Run these from the package worktree.

- Install dependencies with `composer install`.
- Run Pest with `vendor/bin/pest tests/Seam`. Substitute the test directory or file for your chunk.
- Check formatting with `vendor/bin/pint --test src database/migrations tests`.
- Run static analysis with `vendor/bin/phpstan analyse --no-progress`.

Tests use in-memory SQLite and block unfaked Laravel HTTP requests. Fake outbound requests explicitly in tests that exercise an adapter.

Repeat database-specific tests with `GOVERNANCE_TEST_POSTGRES=1 DB_PASSWORD="$test_db_password" vendor/bin/pest tests/Seam`. This runs migrations and rollbacks on the dedicated local `shared_tool_governance` database as `sail` at `127.0.0.1:5432`. On Olivier's development box, read the password from the Homies API environment without printing it. Run this suite serially against an idle, dedicated database.
