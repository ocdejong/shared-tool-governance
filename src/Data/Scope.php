<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class Scope
{
    public function __construct(
        public string $hostScope,
        public string $householdId,
    ) {}

    public function equals(self $other): bool
    {
        return $this->hostScope === $other->hostScope && $this->householdId === $other->householdId;
    }
}
