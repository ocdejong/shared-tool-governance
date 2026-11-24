<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Authorization\CallPolicy;
use Ocdejong\SharedToolGovernance\Configuration;
use Ocdejong\SharedToolGovernance\Contracts\RuleEvaluator;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceApproval;
use Ocdejong\SharedToolGovernance\Models\GovernanceGrant;
use Ocdejong\SharedToolGovernance\Models\GovernanceInvocation;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;
use Ocdejong\SharedToolGovernance\Tests\Authorization\Fixture;

it('boots with package defaults and caches an env-backed published override', function (): void {
    $app = app();
    $root = sys_get_temp_dir().'/governance-config-'.Str::ulid();
    File::ensureDirectoryExists($root.'/bootstrap/cache');
    $bootstrap = $root.'/bootstrap/app.php';

    try {
        File::put($bootstrap, <<<'BOOTSTRAP'
            <?php
            return Illuminate\Foundation\Application::configure(dirname(__DIR__))
                ->withProviders([Ocdejong\SharedToolGovernance\GovernanceServiceProvider::class], false)
                ->create();
            BOOTSTRAP);

        /** @var Application $host */
        $host = require $bootstrap;
        $kernel = $host->make(Kernel::class);
        $kernel->bootstrap();
        $configPath = $host->configPath('shared-tool-governance.php');
        $defaults = [
            'grant_lifetime_minutes' => 5,
            'approval_lifetime_minutes' => 15,
            'retention_days' => 30,
        ];
        expect(File::exists($configPath))->toBeFalse()
            ->and(config('shared-tool-governance'))->toBe($defaults);

        expect($kernel->call('vendor:publish', ['--tag' => 'shared-tool-governance-config']))->toBe(0, $kernel->output())
            ->and(File::getRequire($configPath))->toBe($defaults);
        File::put($root.'/.env', "STG_TEST_APPROVAL_LIFETIME_MINUTES=7\n");
        File::put($configPath, '<?php return ["approval_lifetime_minutes" => env("STG_TEST_APPROVAL_LIFETIME_MINUTES", 15)];');
        expect($kernel->call('config:cache'))->toBe(0, $kernel->output());

        /** @var Application $cached */
        $cached = require $bootstrap;
        $cached->make(Kernel::class)->bootstrap();
        expect($cached->configurationIsCached())->toBeTrue()
            ->and($cached['config']->get('shared-tool-governance'))->toBe([
                'grant_lifetime_minutes' => 5,
                'approval_lifetime_minutes' => '7',
                'retention_days' => 30,
            ])
            ->and(Configuration::duration('approval_lifetime_minutes'))->toBe(7);
    } finally {
        Application::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        File::deleteDirectory($root);
    }
});

it('applies configured lifetimes at issuance and retention when pruning', function (int|string $grantMinutes, int|string $approvalMinutes, int|string $retentionDays): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake();

    config([
        'shared-tool-governance.grant_lifetime_minutes' => $grantMinutes,
        'shared-tool-governance.approval_lifetime_minutes' => $approvalMinutes,
        'shared-tool-governance.retention_days' => $retentionDays,
    ]);

    $fixture = new Fixture;
    expect($fixture->authorizer->authorize($fixture->call())->status)->toBe('granted');
    $grant = GovernanceGrant::query()->sole();
    expect($grant->getAttribute('expires_at')->getTimestamp() - $grant->getAttribute('created_at')->getTimestamp())->toBe(120);

    app()->forgetInstance(CallPolicy::class);
    $host = new ApprovalHost;
    $host->bind();
    $authorizer = app(Authorizer::class);
    $now = now()->startOfSecond();
    $this->travelTo($now);
    expect($authorizer->authorize($host->call('pending'))->status)->toBe('pending_approval');

    $deadline = $now->copy()->addMinutes(7)->getTimestamp();
    $pending = GovernanceInvocation::query()->where('invocation_id', 'pending')->sole();
    expect($pending->getAttribute('approval_expires_at')?->getTimestamp())->toBe($deadline)
        ->and(GovernanceApproval::query()->sole()->getAttribute('expires_at')->getTimestamp())->toBe($deadline);

    app()->instance(RuleEvaluator::class, app(ResourceRules::class));
    expect(app(Authorizer::class)->authorize($host->call('denied'))->status)->toBe('denied');

    config([
        'shared-tool-governance.grant_lifetime_minutes' => 60,
        'shared-tool-governance.approval_lifetime_minutes' => 60,
    ]);
    $this->travelTo($now->copy()->addMinutes(7)->subSecond());
    app(ApprovalWork::class)->recover();
    Queue::assertNotPushed(ResumeApproval::class);
    expect(app(Approvals::class)->receipt('pending'))->toBe(['status' => 'pending_approval']);

    $this->travelTo($now->copy()->addMinutes(7));
    app(ApprovalWork::class)->recover();
    Queue::assertPushed(ResumeApproval::class, 1);
    ApprovalHost::runResumeJobs();
    expect($pending->refresh()->getAttribute('status'))->toBe('expired')
        ->and($pending->getAttribute('approval_expires_at')->getTimestamp())->toBe($deadline)
        ->and($grant->refresh()->getAttribute('expires_at')->getTimestamp() - $grant->getAttribute('created_at')->getTimestamp())->toBe(120);

    $denied = GovernanceInvocation::query()->where('invocation_id', 'denied')->sole();
    $this->travelTo($now->copy()->addDays(2)->subSecond());
    expect(Artisan::call('shared-tool-governance:prune'))->toBe(0, Artisan::output())
        ->and($denied->refresh()->getRawOriginal('frozen_inputs'))->not->toBeNull();
    $this->travelTo($now->copy()->addDays(2));
    expect(Artisan::call('shared-tool-governance:prune'))->toBe(0, Artisan::output())
        ->and($denied->refresh()->getRawOriginal('frozen_inputs'))->toBeNull();
})->with([
    'integers' => [2, 7, 2],
    'env strings' => ['2', '7', '02'],
]);

it('rejects invalid durations before issuing work or pruning private inputs', function (mixed $invalid): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake();

    $fixture = new Fixture;
    config(['shared-tool-governance.grant_lifetime_minutes' => $invalid]);
    expect(fn () => $fixture->authorizer->authorize($fixture->call()))
        ->toThrow(InvalidArgumentException::class, 'shared-tool-governance.grant_lifetime_minutes');
    expect(GovernanceGrant::query()->count())->toBe(0);

    app()->forgetInstance(CallPolicy::class);
    $host = new ApprovalHost;
    $host->bind();
    $authorizer = app(Authorizer::class);
    config(['shared-tool-governance.approval_lifetime_minutes' => $invalid]);
    expect(fn () => $authorizer->authorize($host->call('pending')))
        ->toThrow(InvalidArgumentException::class, 'shared-tool-governance.approval_lifetime_minutes');
    expect(GovernanceInvocation::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    $host->deny = true;
    expect($authorizer->authorize($host->call('denied'))->status)->toBe('denied');
    $denied = GovernanceInvocation::query()->sole();
    $inputs = $denied->getRawOriginal('frozen_inputs');
    expect($inputs)->not->toBeNull();

    $this->travel(1)->day();
    config(['shared-tool-governance.retention_days' => $invalid]);
    expect(fn () => Artisan::call('shared-tool-governance:prune'))
        ->toThrow(InvalidArgumentException::class, 'shared-tool-governance.retention_days');
    expect($denied->refresh()->getRawOriginal('frozen_inputs'))->toBe($inputs);
})->with([
    'non-numeric string' => ['tomorrow'],
    'zero string' => ['0'],
    'negative integer' => [-1],
    'fractional string' => ['1.5'],
    'overflowing string' => [str_repeat('9', 25)],
    'above the duration limit' => ['2147483648'],
    'boolean' => [true],
]);

it('expires a pending invocation seeded before the deadline migration on its original schedule', function (): void {
    $directory = dirname(__DIR__, 2).'/database/migrations';
    expect(Artisan::call('migrate', ['--realpath' => true, '--path' => [
        $directory.'/2026_11_24_000000_create_governance_tables.php',
        $directory.'/2026_11_24_000001_add_receipt_targets_to_governance_invocations.php',
        $directory.'/2026_11_24_000002_allow_encrypted_invocation_reasons.php',
        $directory.'/2026_11_24_000003_move_invocation_reason_into_frozen_inputs.php',
    ]]))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(function (): void {
        expect(Artisan::call('migrate:reset'))->toBe(0, Artisan::output());
    });

    Queue::fake();
    $now = now()->startOfSecond();
    $row = GovernanceInvocation::query()->forceCreate([
        'host_scope' => 'tenant',
        'household_id' => 'home',
        'invocation_id' => 'legacy',
        'tool_name' => 'reschedule',
        'tool_version' => '1',
        'action_key' => 'items.reschedule',
        'requester_id' => 'requester',
        'runtime_id' => 'runtime',
        'executor_id' => 'worker',
        'audience' => ['requester'],
        'fingerprint' => str_repeat('a', 64),
        'status' => 'pending_approval',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    expect(DB::table('governance_invocations')->where('id', $row->getKey())->value('approval_expires_at'))->toBeNull();
    config(['shared-tool-governance.approval_lifetime_minutes' => 3]);
    $host = new ApprovalHost;
    $host->bind();

    $this->travelTo($now->copy()->addMinutes(15)->subSecond());
    app(ApprovalWork::class)->recover();
    Queue::assertNotPushed(ResumeApproval::class);
    expect(app(Approvals::class)->receipt('legacy'))->toBe(['status' => 'pending_approval']);

    $this->travelTo($now->copy()->addMinutes(15));
    app(ApprovalWork::class)->recover();
    Queue::assertPushed(ResumeApproval::class, 1);
    ApprovalHost::runResumeJobs();
    expect($row->refresh()->getAttribute('status'))->toBe('expired');
});
