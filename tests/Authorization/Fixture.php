<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Tests\Authorization;

use InvalidArgumentException;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final class Fixture implements ResourceAuthority, RuleEvaluator, TrustedInvocationContext
{
    public ToolRegistry $tools;

    public Authorizer $authorizer;

    public GrantService $grants;

    public InvocationContext $context;

    public string $owner = 'person-2';

    public bool $membersActive = true;

    public int $evaluations = 0;

    /** @var array<string, Verdict> */
    public array $verdicts = [];

    public function __construct()
    {
        $this->context = new InvocationContext(new Scope('tenant-1', 'home-1'), 'person-1', 'agent-1', ['person-1', 'person-2']);
        $this->tools = new ToolRegistry;
        $this->tools->register(new ToolDefinition(
            name: 'update_item',
            version: '1',
            actionKey: 'items.update',
            inputSchema: ['type' => 'object'],
            normalizeArguments: static function (array $arguments, InvocationContext $context): array {
                if (! is_string($arguments['date'] ?? null)) {
                    throw new InvalidArgumentException('Expected a date.');
                }

                return $arguments + ['timezone' => 'Europe/Amsterdam'];
            },
            resolveTargets: static fn (array $arguments, InvocationContext $context): array => [
                new ResourceReference('item', 'item-1', 'r1'),
                new ResourceReference('item', is_string($arguments['also'] ?? null) ? $arguments['also'] : 'item-2', 'r1'),
            ],
            executorId: 'items-worker',
            effect: 'Update the item schedule',
            resultSchema: ['type' => 'object'],
        ));
        $policy = new CallPolicy($this->tools, $this, $this, $this);
        $this->authorizer = new Authorizer($policy);
        $this->grants = new GrantService($policy, $this);
    }

    /** @param array<string, mixed> $arguments */
    public function call(
        string $id = 'call-1',
        string $tool = 'update_item',
        string $version = '1',
        array $arguments = ['date' => '2026-11-25'],
        string $reason = 'Move the repair visit',
        ?InvocationContext $context = null,
        string $executor = 'items-worker',
    ): Invocation {
        return new Invocation($id, $tool, $version, $arguments, $reason, $context ?? $this->context, $executor);
    }

    public function current(): InvocationContext
    {
        return $this->context;
    }

    public function isMember(Scope $scope, string $personId): bool
    {
        return $this->membersActive && in_array($personId, ['person-1', 'person-2'], true);
    }

    public function ownerOf(Scope $scope, ResourceReference $resource): ?OwnerReference
    {
        return match ($resource->id) {
            'item-1' => new OwnerReference(OwnerType::PERSON, $this->owner),
            'item-2' => new OwnerReference(OwnerType::HOUSEHOLD, $scope->householdId),
            default => null,
        };
    }

    public function canManageRules(Scope $scope, string $personId, ResourceReference $resource, OwnerReference $owner): bool
    {
        return false;
    }

    public function approverFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?string
    {
        return null;
    }

    public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation
    {
        $this->evaluations++;

        return new RuleEvaluation($this->verdicts[$resource->id] ?? Verdict::ALLOW, $actionKey, 3, 'Fixture policy');
    }
}
