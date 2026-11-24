<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class RuleEvaluation
{
    public function __construct(
        public Verdict $verdict,
        public ?string $matchedActionKey,
        public ?int $revision,
        public string $explanation,
    ) {}
}
