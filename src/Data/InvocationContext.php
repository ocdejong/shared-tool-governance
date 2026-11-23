<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class InvocationContext
{
    /** @param list<string> $audience */
    public function __construct(
        public Scope $scope,
        public string $requesterId,
        public string $runtimeId,
        public array $audience,
    ) {}
}
