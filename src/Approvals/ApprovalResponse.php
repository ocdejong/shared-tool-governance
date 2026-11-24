<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

final readonly class ApprovalResponse
{
    /** @param 'recorded'|'settled'|'refused' $status */
    private function __construct(
        public string $status,
        public ?ApprovalDecision $decision,
        public bool $accepted,
    ) {}

    public static function recorded(): self
    {
        return new self('recorded', null, true);
    }

    public static function settled(ApprovalDecision $decision, bool $accepted): self
    {
        return new self('settled', $decision, $accepted);
    }

    public static function refused(): self
    {
        return new self('refused', null, false);
    }
}
