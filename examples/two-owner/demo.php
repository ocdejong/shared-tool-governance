<?php

declare(strict_types=1);

use App\Providers\TwoOwnerServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryMode;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $data */
function show(string $label, array $data): void
{
    echo $label.' '.json_encode($data, JSON_THROW_ON_ERROR).PHP_EOL;
}

function runDemo(string $phase): void
{
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $host = app(TwoOwnerServiceProvider::class);
    $cache = Cache::store('database');
    $approvals = app(Approvals::class);
    $jobs = DB::table('jobs')->where('queue', 'default');
    $callId = TwoOwnerServiceProvider::CALL_ID;
    check(Http::preventingStrayRequests(), 'Stray HTTP must be blocked in the demo.');

    if ($phase === 'verify') {
        $receipt = $approvals->receipt($callId);
        check($receipt === ['status' => 'granted', 'outcome' => 'success'], 'The resumed call did not succeed.');
        check($cache->get('demo.executions') === 1, 'Expected one executor call after the restart.');
        check($jobs->count() === 0 && DB::table('failed_jobs')->count() === 0, 'The worker left queued or failed jobs.');
        check(DB::table('governance_grants')->count() === 1
            && DB::table('governance_grants')->whereNotNull('redeemed_at')->count() === 1, 'Expected one redeemed grant.');

        $suggestions = $cache->get('demo.model-result');
        check($suggestions === ['suggestions' => ['10:00–10:30']], 'Expected only the shared suggestion in the model result.');
        show('Model result', $suggestions);

        $request = $cache->get('demo.request');
        $approval = $cache->get('demo.approval');
        check(is_array($request) && is_array($approval), 'Host receipt data is missing.');
        check($approval['sender'] === 'Pauline' && $request['requester'] === 'Tom'
            && $request['reason'] === 'Find a time for our planning call.', 'Unexpected receipt identity or reason.');
        show('Requester receipt', [
            'requester' => $request['requester'],
            'reason' => $request['reason'],
            'approver' => $approval['sender'],
            'outcome' => $receipt['outcome'] ?? null,
        ]);

        $reply = new VerifiedReply($host->current()->scope, $approval['sender'], $approval['card'], $approval['reply']);
        check($approvals->decide($approval['id'], ApprovalDecision::APPROVE, $reply), 'A duplicate reply should be idempotent.');
        check($jobs->count() === 0 && $cache->get('demo.executions') === 1, 'The repeat reply queued or ran the call again.');
        echo 'Two-owner demo passed. One executor call after a fresh worker start.'.PHP_EOL;

        return;
    }

    check($phase === 'prepare', 'Expected prepare or verify.');
    $host->seedOwnerRules();
    $context = $host->current();
    $scope = $context->scope;
    $inbox = app(LocalInbox::class);
    $cache->put('demo.executions', 0);

    $tools = app(ToolDiscovery::class);
    $targets = $host->discoveryTargets();
    $all = $tools->discover($scope, $targets, DiscoveryMode::ALL, 'calendar');
    check(array_column($all->actions['calendar.details'] ?? [], 'verdict') === [Verdict::ALLOW, Verdict::DENY],
        'Expected Tom to allow details and Pauline to deny them.');
    $discovery = $tools->discover($scope, $targets, DiscoveryMode::MATCH, 'calendar');
    $permissions = [];
    foreach ($discovery->actions as $action => $owners) {
        foreach ($owners as $owner) {
            $permissions[$action][$owner->owner->id] = $owner->verdict?->name;
        }
    }

    check($permissions === ['calendar.availability' => ['Tom' => 'ALLOW', 'Pauline' => 'DELEGATE']]
        && $discovery->unresolved === [], 'MATCH must preserve both verdicts and omit denied details.');
    check($jobs->count() === 0
        && DB::table('governance_grants')->count() === 0
        && DB::table('governance_outbox_messages')->count() === 0
        && $inbox->cards($scope, 'Tom') === [] && $inbox->cards($scope, 'Pauline') === []
        && $cache->get('demo.executions') === 0, 'Discovery produced a grant, message or executor call.');
    show('MATCH', $permissions);

    $request = new Invocation(
        $callId,
        'joint_availability',
        '1',
        ['date' => '2026-12-02'],
        'Find a time for our planning call.',
        $context,
        TwoOwnerServiceProvider::class,
    );
    $cache->put('demo.request', [
        'id' => $request->id,
        'requester' => $context->requesterId,
        'runtime' => $context->runtimeId,
        'reason' => $request->reason,
    ]);
    $result = app(Authorizer::class)->authorize($request);
    check($result->jsonSerialize() === ['status' => 'pending_approval', 'request_id' => $callId]
        && $result->grantToken() === null, 'Expected an audience-safe pending response without a grant.');
    show('Model status', $result->jsonSerialize());

    check($jobs->count() === 1, 'Expected one queued card delivery.');
    check(Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 1]) === 0, 'Card delivery worker failed.');
    $cards = $inbox->cards($scope, 'Pauline');
    check(count($cards) === 1 && $inbox->cards($scope, 'Tom') === [], 'Only Pauline should receive one private card.');
    $card = $cards[0];
    check(str_contains($card->message, 'Requester: Tom')
        && str_contains($card->message, 'Find a time for our planning call.')
        && str_contains($card->message, 'Suggest common times for 2026-12-02'),
        'The card should describe the exact request.');
    echo 'Private approval card delivered to Pauline.'.PHP_EOL;

    $reply = new VerifiedReply($scope, 'Pauline', $card->cardId, 'demo-pauline-reply');
    check($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, $reply), 'The verified reply from Pauline was refused.');
    $cache->put('demo.approval', [
        'id' => $card->approvalId,
        'sender' => $reply->senderId,
        'card' => $reply->cardId,
        'reply' => $reply->replyId,
    ]);
    check($approvals->receipt($callId) === ['status' => 'approved']
        && $jobs->count() === 1
        && DB::table('governance_grants')->count() === 0
        && $cache->get('demo.executions') === 0, 'Expected one waiting resume job and zero executions before restart.');
    echo 'Approval recorded. Resume job waiting in the database queue.'.PHP_EOL;
}

require 'vendor/autoload.php';

try {
    runDemo($_SERVER['argv'][1] ?? '');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
