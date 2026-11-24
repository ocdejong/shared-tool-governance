<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class ResourceReference
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $revision = null,
        public ?string $accountAlias = null,
    ) {}
}
