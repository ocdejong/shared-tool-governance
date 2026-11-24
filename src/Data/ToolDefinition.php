<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

use Closure;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalPreview;

final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  Closure(array<string, mixed>, InvocationContext): array<string, mixed>  $normalizeArguments
     * @param  Closure(array<string, mixed>, InvocationContext): list<ResourceReference>  $resolveTargets
     * @param  array<string, mixed>  $resultSchema
     * @param  (Closure(Invocation, list<ResourceReference>, string): ?ApprovalPreview)|null  $approvalPreview
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $actionKey,
        public array $inputSchema,
        public Closure $normalizeArguments,
        public Closure $resolveTargets,
        public string $executorId,
        public string $effect,
        public array $resultSchema,
        public ?Closure $approvalPreview = null,
    ) {}
}
