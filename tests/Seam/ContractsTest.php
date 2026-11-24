<?php

declare(strict_types=1);

use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\InvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\RuleEvaluation;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;
use Ocdejong\SharedToolGovernance\Data\Verdict;

it('passes a normalized call and verified scope to the registered target resolver', function (): void {
    $context = new InvocationContext(new Scope('tenant-1', 'home-1'), 'person-1', 'assistant-1', ['person-1']);
    $tool = new ToolDefinition(
        name: 'update_item',
        version: '1',
        actionKey: 'items.update',
        inputSchema: ['type' => 'object'],
        normalizeArguments: fn (array $arguments, InvocationContext $context) => $arguments + ['timezone' => 'Europe/Amsterdam'],
        resolveTargets: fn (array $arguments, InvocationContext $context) => [new ResourceReference('item', 'item-1')],
        executorId: 'items-worker',
        effect: 'Update the item schedule',
        resultSchema: ['type' => 'object'],
    );
    $call = new Invocation('call-1', $tool->name, $tool->version, ['date' => '2026-11-25'], 'Move the repair visit', $context, $tool->executorId);
    $normalized = ($tool->normalizeArguments)($call->arguments, $call->context);

    expect($normalized)->toBe(['date' => '2026-11-25', 'timezone' => 'Europe/Amsterdam'])
        ->and(($tool->resolveTargets)($normalized, $call->context))->toEqual([new ResourceReference('item', 'item-1')])
        ->and($call->reason)->toBe('Move the repair visit');
});

it('lets authorization receive the effective verdict and deciding revision through a rule evaluator', function (): void {
    $evaluator = new class implements RuleEvaluator
    {
        public function evaluate(Scope $scope, ResourceReference $resource, OwnerReference $owner, string $actionKey): RuleEvaluation
        {
            return new RuleEvaluation(Verdict::DELEGATE, 'calendar', 3, 'Owner approval required');
        }
    };

    $result = $evaluator->evaluate(
        new Scope('tenant-1', 'home-1'),
        new ResourceReference('calendar', 'calendar-1'),
        new OwnerReference(OwnerType::PERSON, 'person-1'),
        'calendar.availability',
    );

    expect(array_column(Verdict::cases(), 'value'))->toBe(['ALLOW', 'DENY', 'DELEGATE'])
        ->and($result->verdict)->toBe(Verdict::DELEGATE)
        ->and($result->matchedActionKey)->toBe('calendar')
        ->and($result->revision)->toBe(3);
});
