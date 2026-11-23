<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class ExecutionResult
{
    /** @param array<string, mixed> $result */
    public function __construct(
        public ExecutionStatus $status,
        public array $result = [],
        public ?string $providerReference = null,
    ) {}
}
