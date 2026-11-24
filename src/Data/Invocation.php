<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class Invocation
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $toolName,
        public string $toolVersion,
        public array $arguments,
        public string $reason,
        public InvocationContext $context,
        public string $executorId,
    ) {}
}
