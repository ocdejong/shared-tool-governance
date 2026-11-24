<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Data;

final readonly class ApprovalRule
{
    /** @var list<string> */
    public array $eligiblePeople;

    /** @param list<string> $eligiblePeople */
    public function __construct(public ApprovalMode $mode, array $eligiblePeople)
    {
        $people = array_unique($eligiblePeople, SORT_STRING);
        sort($people, SORT_STRING);

        $this->eligiblePeople = $people;
    }
}
