<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface ResourceAuthority
{
    public function isMember(Scope $scope, string $personId): bool;

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference;

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool;

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string;
}
