<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

use InvalidArgumentException;

final readonly class Scope
{
    public string $workspaceId;

    /** @deprecated Use workspaceId. */
    public string $householdId;

    public function __construct(
        public string $hostScope,
        ?string $workspaceId = null,
        ?string $householdId = null,
    ) {
        if ($workspaceId === null && $householdId === null) {
            throw new InvalidArgumentException('A workspace ID is required.');
        }
        if ($workspaceId !== null && $householdId !== null && $workspaceId !== $householdId) {
            throw new InvalidArgumentException('The workspace and legacy household IDs must match.');
        }

        $this->workspaceId = $this->householdId = $workspaceId ?? $householdId;
    }

    public function equals(self $other): bool
    {
        return $this->hostScope === $other->hostScope && $this->workspaceId === $other->workspaceId;
    }

    /** @return array{hostScope: string, householdId: string} */
    public function __serialize(): array
    {
        // Queued completion jobs can be read across package versions.
        return ['hostScope' => $this->hostScope, 'householdId' => $this->workspaceId];
    }

    /** @param array{hostScope: string, householdId: string} $data */
    public function __unserialize(array $data): void
    {
        $this->hostScope = $data['hostScope'];
        $this->workspaceId = $this->householdId = $data['householdId'];
    }
}
