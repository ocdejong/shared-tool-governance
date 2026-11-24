<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Database;

use Illuminate\Database\Migrations\Migrator;

final class GovernanceMigrator extends Migrator
{
    /**
     * @param  array<array-key, object>  $migrations
     * @param  list<string>|string  $paths
     * @param  array<string, mixed>  $options
     * @return array<string>
     */
    protected function rollbackMigrations(array $migrations, $paths, array $options)
    {
        if (! ($options['pretend'] ?? false)) {
            // Laravel filters the ledger by path inside this method, then commits each down separately.
            $files = $this->getMigrationFiles($paths);
            $selected = array_intersect_key($files, array_flip(array_column($migrations, 'migration')));
            if (isset($selected[ApprovalRollback::ELIGIBLE_CARDS])) {
                ApprovalRollback::withoutEligibleCards($this->resolveConnection($this->connection));
            } elseif (isset($selected[ApprovalRollback::GROUP_PROMPTS])) {
                ApprovalRollback::withoutGroupPrompts($this->resolveConnection($this->connection));
            }
        }

        return parent::rollbackMigrations($migrations, $paths, $options);
    }
}
