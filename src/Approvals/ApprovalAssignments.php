<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Approvals;

/** @internal */
final class ApprovalAssignments
{
    /** @param list<list<string>> $slots Each slot needs a distinct person. */
    public static function possible(array $slots): bool
    {
        $assigned = [];
        foreach (array_keys($slots) as $slot) {
            $visited = [];
            if (! self::assign($slot, $slots, $assigned, $visited)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<list<string>>  $slots
     * @return list<string>
     */
    public static function viablePeople(array $slots, int $slot): array
    {
        $people = $slots[$slot];
        if (count($slots) === 1 || count($people) === 1) {
            return $people;
        }

        $viable = [];
        foreach ($people as $person) {
            $slots[$slot] = [$person];
            if (self::possible($slots)) {
                $viable[] = $person;
            }
        }

        return $viable;
    }

    /**
     * @param  list<list<string>>  $slots
     * @param  array<array-key, int>  $assigned
     * @param  array<array-key, true>  $visited
     */
    private static function assign(int $slot, array $slots, array &$assigned, array &$visited): bool
    {
        foreach ($slots[$slot] as $person) {
            if (isset($visited[$person])) {
                continue;
            }

            $visited[$person] = true;
            if (! isset($assigned[$person]) || self::assign($assigned[$person], $slots, $assigned, $visited)) {
                $assigned[$person] = $slot;

                return true;
            }
        }

        return false;
    }
}
