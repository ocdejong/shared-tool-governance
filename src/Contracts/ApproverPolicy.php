<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\ApprovalRule;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface ApproverPolicy
{
    /**
     * Return null to use ResourceAuthority::approverFor() for this resource.
     * An any rule receives cards only for people who can satisfy that owner's
     * requirement in at least one distinct assignment across the call.
     * The package freezes those recipients before sending the cards.
     */
    public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?ApprovalRule;
}
