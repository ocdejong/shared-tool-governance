<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class OwnerReference
{
    public function __construct(
        public OwnerType $type,
        public string $id,
    ) {}
}
