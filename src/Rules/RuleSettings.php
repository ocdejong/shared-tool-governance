<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Rules;

use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Verdict;

final readonly class RuleSettings
{
    /** @param array<array-key, Verdict> $rules */
    public function __construct(
        public int $revision,
        public bool $enabled,
        public array $rules,
    ) {}

    public function evaluate(string $actionKey): RuleEvaluation
    {
        $revision = $this->revision === 0 ? null : $this->revision;
        if (! $this->enabled) {
            return new RuleEvaluation(Verdict::DENY, null, $revision, 'Governed access is switched off.');
        }

        $key = $actionKey;
        while (true) {
            $verdict = $this->rules[$key] ?? null;
            if ($verdict !== null) {
                return new RuleEvaluation($verdict, $key, $revision, "Rule for {$key}: {$verdict->value}.");
            }

            $separator = strrpos($key, '.');
            if ($separator === false) {
                return new RuleEvaluation(Verdict::DENY, null, $revision, 'No explicit rule applies. Access defaults to DENY.');
            }

            $key = substr($key, 0, $separator);
        }
    }
}
