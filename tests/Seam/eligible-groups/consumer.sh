#!/usr/bin/env bash
set -euo pipefail

fixture=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo=$(git -C "$fixture" rev-parse --show-toplevel)
cut=$(git -C "$repo" rev-parse '546281a^{commit}')
target=$(git -C "$repo" rev-parse --verify --end-of-options "${1:-HEAD}^{commit}")
driver=sqlite
if [[ -v DB_DATABASE ]]; then
    [[ $DB_DATABASE == shared_tool_governance ]] || {
        echo 'Use the dedicated shared_tool_governance PostgreSQL database.' >&2
        exit 2
    }
    exec 9>/tmp/shared-tool-governance-pgsql.lock
    flock 9
    driver=pgsql
fi

scratch=$(mktemp -d)
schema=
cleanup() {
    if [[ -n $schema ]]; then
        host_db "DROP SCHEMA IF EXISTS $schema CASCADE" || {
            echo "Could not remove test schema $schema" >&2
            return 1
        }
    fi
    rm -rf -- "$scratch"
}
trap cleanup EXIT

unset DB_URL
export DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-5432}" DB_USERNAME="${DB_USERNAME:-sail}"
host_db() {
    php -r '
        $connection = new PDO(
            "pgsql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE"),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD") ?: ""
        );
        $connection->exec($argv[1]);
    ' "$1"
}
if [[ $driver == pgsql ]]; then
    schema="eligible_groups_${BASHPID}_${RANDOM}"
    host_db "CREATE SCHEMA $schema"
fi

export APP_ENV=testing APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
export DB_CONNECTION="$driver" DB_SEARCH_PATH="${schema:-public}" QUEUE_CONNECTION=database
if [[ $driver == sqlite ]]; then
    export DB_DATABASE="$scratch/consumer.sqlite"
    touch "$DB_DATABASE"
fi
compose() {
    composer --no-interaction --no-ansi --no-plugins "$@"
}

for stage in cut target; do
    mkdir "$scratch/$stage"
    git -C "$repo" archive "${!stage}" | tar -x -C "$scratch/$stage"
done

# Reuse Composer's dependency cache to provision a clean Laravel application.
composer --no-interaction --no-ansi --no-plugins create-project --no-dev --no-scripts --prefer-dist --no-progress --quiet \
    laravel/laravel "$scratch/consumer" '^13.0'
cd "$scratch/consumer"
php -r '
    $config = file_get_contents("config/database.php");
    $old = "\x27search_path\x27 => \x27public\x27";
    $config = str_replace($old, "\x27search_path\x27 => env(\x27DB_SEARCH_PATH\x27, \x27public\x27)", $config, $replaced);
    if ($replaced !== 1 || file_put_contents("config/database.php", $config) === false) {
        throw new RuntimeException("Could not set the PostgreSQL test schema.");
    }
'
cp "$fixture/consumer.php" ./eligible-groups.php
install_package() {
    local stage=$1
    local version="dev-groups-$stage-${!stage}"
    compose config repositories.governance \
        "{\"type\":\"path\",\"url\":\"../$stage\",\"options\":{\"symlink\":false,\"versions\":{\"ocdejong/shared-tool-governance\":\"$version\"}}}"
    compose require --update-no-dev --no-progress --quiet "ocdejong/shared-tool-governance:$version"
    php artisan migrate --force --no-ansi --quiet
}

install_package cut
php eligible-groups.php seed
php eligible-groups.php worker
install_package target
php eligible-groups.php upgrade
php eligible-groups.php worker
php eligible-groups.php verify-upgrade
echo "Upgrade passed ($driver): separate owners and a single approver completed, combined legacy card expired."

if [[ $driver == pgsql ]]; then
    php eligible-groups.php prepare-races
    php eligible-groups.php group-worker
    php eligible-groups.php race
    php eligible-groups.php group-worker
    php eligible-groups.php group-worker
    php eligible-groups.php verify-races
    echo 'PostgreSQL races passed: opposite decisions and duplicate replies settled once, with a fresh worker.'
fi
