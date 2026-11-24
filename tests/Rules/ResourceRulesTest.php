<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ocdejong\SharedToolGovernance\Contracts\ResourceAuthority;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\OwnerType;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Rules\RuleAccessDenied;
use Ocdejong\SharedToolGovernance\Rules\StaleRuleRevision;
use Ocdejong\SharedToolGovernance\Tests\Rules\RulesHost;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
});

it('selects the closest explicit rule for each owner and explains its source', function (string $person, string $action, bool $enabled, Verdict $verdict, ?string $matched, string $explanation): void {
    $host = new RulesHost;
    $host->requester = $person;
    $scope = $host->current()->scope;
    $resource = new ResourceReference('calendar', $person);
    $rules = new ResourceRules($host, $host);
    $settings = $rules->setRules($scope, $resource, 0, [
        'enabled' => $enabled,
        'rules' => [
            'calendar' => Verdict::DELEGATE,
            'calendar.events' => Verdict::ALLOW,
            'calendar.events.delete' => Verdict::DENY,
        ],
    ]);

    $result = $rules->evaluate($scope, $resource, new OwnerReference(OwnerType::PERSON, $person), $action);

    expect($settings->revision)->toBe(1)
        ->and($result->verdict)->toBe($verdict)
        ->and($result->matchedActionKey)->toBe($matched)
        ->and($result->revision)->toBe(1)
        ->and($result->explanation)->toContain($explanation);
})->with([
    'parent allow' => ['tom', 'calendar.events.read', true, Verdict::ALLOW, 'calendar.events', 'calendar.events'],
    'root delegate' => ['pauline', 'calendar.availability', true, Verdict::DELEGATE, 'calendar', 'calendar'],
    'closest deny' => ['lee', 'calendar.events.delete', true, Verdict::DENY, 'calendar.events.delete', 'calendar.events.delete'],
    'off beats allow' => ['tom', 'calendar.events.read', false, Verdict::DENY, null, 'off'],
    'unset root' => ['pauline', 'contacts.read', true, Verdict::DENY, null, 'No explicit rule'],
]);

it('uses a household rule only for the verified household owner', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $resource = new ResourceReference('calendar', 'shared');
    $rules = new ResourceRules($host, $host);

    expect($rules->getRules($scope, $resource)->revision)->toBe(0)
        ->and($rules->getRules($scope, $resource)->evaluate('calendar.read')->verdict)->toBe(Verdict::DENY);
    $rules->setRules($scope, $resource, 0, ['rules' => ['calendar' => Verdict::DELEGATE]]);

    expect($rules->evaluate($scope, $resource, new OwnerReference(OwnerType::HOUSEHOLD, 'home'), 'calendar.read')->verdict)->toBe(Verdict::DELEGATE)
        ->and($rules->evaluate($scope, $resource, new OwnerReference(OwnerType::PERSON, 'tom'), 'calendar.read')->verdict)->toBe(Verdict::DENY);

    $host->requester = 'pauline';
    expect(fn () => $rules->getRules($scope, $resource))->toThrow(RuleAccessDenied::class);
});

it('refuses a peer’s personal rules and lookups outside the verified scope', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $peer = new ResourceReference('calendar', 'pauline');
    $own = new ResourceReference('calendar', 'tom');
    $elsewhere = new Scope('tenant', 'other-home');
    $rules = new ResourceRules($host, $host);

    expect(fn () => $rules->getRules($scope, $peer))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $rules->setRules($scope, $peer, 0, ['enabled' => false]))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $rules->getRules($elsewhere, $own))->toThrow(RuleAccessDenied::class)
        ->and(fn () => $rules->setRules(new Scope('other-tenant', 'home'), $own, 0, ['enabled' => false]))->toThrow(RuleAccessDenied::class);

    $host->members = ['pauline', 'lee'];
    expect(fn () => $rules->getRules($scope, $own))->toThrow(RuleAccessDenied::class);
});

it('rejects stale edits and removes an override without replacing unrelated rules', function (): void {
    $host = new RulesHost;
    $scope = $host->current()->scope;
    $resource = new ResourceReference('calendar', 'tom');
    $rules = new ResourceRules($host, $host);

    $rules->setRules($scope, $resource, 0, ['rules' => ['calendar' => Verdict::ALLOW]]);
    expect(fn () => $rules->setRules($scope, $resource, 0, ['enabled' => false]))->toThrow(StaleRuleRevision::class);
    $rules->setRules($scope, $resource, 1, ['rules' => ['calendar.events' => Verdict::DENY]]);
    $rules->setRules($scope, $resource, 2, ['rules' => ['calendar.events' => null]]);

    $settings = $rules->getRules($scope, $resource);
    expect($settings->revision)->toBe(3)
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->rules)->toBe(['calendar' => Verdict::ALLOW])
        ->and($settings->evaluate('calendar.events')->verdict)->toBe(Verdict::ALLOW);
});

it('resolves the evaluator contract through the Laravel package provider', function (): void {
    $host = new RulesHost;
    app()->instance(ResourceAuthority::class, $host);
    app()->instance(TrustedInvocationContext::class, $host);

    expect(app(RuleEvaluator::class))->toBeInstanceOf(ResourceRules::class);
});
