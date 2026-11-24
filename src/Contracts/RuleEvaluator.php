<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Contracts;

use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;

interface RuleEvaluator
{
    public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation;
}
