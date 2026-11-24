<?php

declare(strict_types=1);

use App\Providers\TwoOwnerServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\Jobs\DeliverApproval;
use Ocdejong\SharedToolGovernance\Jobs\DeliverCompletion;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;

require_once __DIR__.'/../../examples/two-owner/TwoOwnerServiceProvider.php';

it('withholds the completed result when the host audience changes after execution', function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    config(['cache.stores.database.driver' => 'array']);
    Queue::fake()->serializeAndRestore();
    $disk = Storage::fake('local');

    $host = new TwoOwnerServiceProvider(app());
    app()->register($host);
    $host->seedOwnerRules();
    $context = app(TrustedInvocationContext::class)->current();
    $callId = TwoOwnerServiceProvider::CALL_ID;
    $cache = Cache::store('database');
    $cache->put('demo.executions', 0);
    $cache->put('demo.request-context', [
        'id' => $callId,
        'requester' => $context->requesterId,
        'runtime' => $context->runtimeId,
        'audience' => $context->audience,
    ]);

    $result = app(Governance::class)->call($callId, 'joint_availability', '1', ['date' => '2026-12-02'], 'Find a planning time.');
    expect($result['status'])->toBe('pending_approval');
    Queue::pushed(DeliverApproval::class)->each(static fn (DeliverApproval $job) => app()->call([$job, 'handle']));
    $card = app(LocalInbox::class)->cards($context->scope, 'Pauline')[0];
    $approvals = app(Approvals::class);
    expect($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, new VerifiedReply(
        $context->scope, 'Pauline', $card->cardId, 'verified-reply',
    )))->toBeTrue();

    Queue::pushed(ResumeApproval::class)->each(static fn (ResumeApproval $job) => app()->call([$job, 'handle']));
    Queue::assertPushed(DeliverCompletion::class, 1);
    expect($cache->get('demo.executions'))->toBe(1)
        ->and($approvals->receipt($callId))->toBe(['status' => 'granted', 'outcome' => 'success']);

    $cache->put('demo.audience', ['Pauline']);
    Queue::pushed(DeliverCompletion::class)->each(static fn (DeliverCompletion $job) => app()->call([$job, 'handle']));
    Queue::pushed(ResumeApproval::class)->each(static fn (ResumeApproval $job) => app()->call([$job, 'handle']));

    expect($disk->allFiles('completion-inbox'))->toBe([])
        ->and($cache->get('demo.executions'))->toBe(1);
    Queue::assertPushed(DeliverCompletion::class, 1);
});
