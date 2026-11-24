# Shared Tool Governance

The package supports PHP 8.3+ and Laravel 13. Develop with PHP 8.4+ for Pest 5 and enable PDO SQLite. Enable PDO PostgreSQL for database-specific checks.

Run these from the package worktree.

- Install dependencies with `composer install`.
- Run the full signoff with `composer test`: Pest, then Pint, then PHPStan. It stops at the first failure.
- Run Pest with `vendor/bin/pest tests/Seam`. Substitute the test directory or file for your chunk.
- Check formatting with `vendor/bin/pint --test src database/migrations tests`.
- Run static analysis with `vendor/bin/phpstan analyse --no-progress`.

Tests use in-memory SQLite and block unfaked Laravel HTTP requests. Fake outbound requests explicitly in tests that exercise an adapter.

Setting `DB_DATABASE` selects PostgreSQL for every test, using `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD`. Tests refuse any database except the dedicated `shared_tool_governance`. They fail, not skip, when PostgreSQL is unreachable. Run `DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=shared_tool_governance DB_USERNAME=sail DB_PASSWORD="$test_db_password" composer test`. On Olivier's development box, read the password from the Homies API environment without printing it. Run this suite serially against an idle, dedicated database.
