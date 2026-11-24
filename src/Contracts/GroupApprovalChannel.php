<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\GroupApprovalPrompt;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface GroupApprovalChannel
{
    /**
     * Choose a chat and supply only text suitable for its entire audience.
     * Return null to use private delivery for this requirement.
     *
     * @param  list<ResourceReference>  $targets
     * @param  list<string>  $eligiblePeople
     */
    public function promptFor(Invocation $call, array $targets, array $eligiblePeople): ?GroupApprovalPrompt;

    /** Recheck the chat's current authorization to see this exact preview. */
    public function allowsDisclosure(Scope $scope, GroupApprovalPrompt $prompt): bool;

    /**
     * Keep deliveryId as the approval ID for replies.
     * Return the original card ID on retries of the same delivery ID.
     */
    public function deliver(Scope $scope, string $deliveryId, GroupApprovalPrompt $prompt): string;
}
