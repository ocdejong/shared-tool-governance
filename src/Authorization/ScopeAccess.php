<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\Scope;

/** @internal */
final readonly class ScopeAccess
{
    public function __construct(private ResourceAuthority $authority) {}

    public function allows(Scope $scope, Scope $verifiedScope, string ...$people): bool
    {
        if (! $scope->equals($verifiedScope)) {
            return false;
        }

        foreach (array_unique($people, SORT_STRING) as $person) {
            if (! $this->authority->isMember($scope, $person)) {
                return false;
            }
        }

        return true;
    }

    public function allowsContext(Scope $scope, InvocationContext $context): bool
    {
        return $this->allows($scope, $context->scope, $context->requesterId, ...$context->audience);
    }

    public function includesOwner(Scope $scope, OwnerReference $owner): bool
    {
        return match ($owner->type) {
            OwnerType::PERSON => $this->allows($scope, $scope, $owner->id),
            OwnerType::WORKSPACE => $owner->id === $scope->workspaceId,
        };
    }
}
