<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tests\Rules;

use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;

final class RulesHost implements ResourceAuthority, TrustedInvocationContext
{
    public string $requester = 'tom';

    /** @var list<string> */
    public array $audience = ['tom', 'pauline'];

    /** @var list<string> */
    public array $members = ['tom', 'pauline', 'lee'];

    public function current(): InvocationContext
    {
        return new InvocationContext(new Scope('tenant', 'home'), $this->requester, 'assistant', $this->audience);
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $scope == $this->current()->scope && in_array($personId, $this->members, true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        if ($scope != $this->current()->scope || $resource->type !== 'calendar') {
            return null;
        }

        return match ($resource->id) {
            'tom', 'pauline', 'lee' => new OwnerReference(OwnerType::PERSON, $resource->id),
            'shared' => new OwnerReference(OwnerType::HOUSEHOLD, 'home'),
            default => null,
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return $personId === 'tom' || $owner->id === $personId;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): string
    {
        return $owner->type === OwnerType::HOUSEHOLD ? 'tom' : $owner->id;
    }
}
