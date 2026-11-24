<?php

declare(strict_types=1);

use App\Providers\TwoOwnerServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Data\VerifiedReply;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryMode;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\Jobs\DeliverCompletion;
use Ocdejong\SharedToolGovernance\LocalInbox\LocalInbox;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

const REASON = 'Find a time for our planning call.';

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

    if ($phase === 'revoke') {
        check($cache->get('demo.executions') === 1
            && $approvals->receipt($callId) === ['status' => 'granted', 'outcome' => 'success'],
            'Expected a committed success before revoking access.');
        $payload = json_decode((string) $jobs->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        check($payload['displayName'] === DeliverCompletion::class, 'Expected a waiting completion job.');
        $cache->put('demo.audience', ['Pauline']);

        return;
    }

    if ($phase === 'verify-revoked') {
        check($jobs->count() === 0 && DB::table('failed_jobs')->count() === 0, 'Expected the completion worker to finish.');
        check($cache->get('demo.executions') === 1 && Storage::disk('local')->allFiles('completion-inbox') === [],
            'An audience change must leave no inbox entry and must not repeat execution.');
        echo 'Revoked audience received no inbox result. The executor ran once.'.PHP_EOL;

        return;
    }

    if ($phase === 'verify') {
        check($cache->get('demo.executions') === 1, 'Expected one executor call after the restart.');
        check($jobs->count() === 0 && DB::table('failed_jobs')->count() === 0, 'The worker left queued or failed jobs.');
        check(DB::table('governance_grants')->count() === 1
            && DB::table('governance_grants')->whereNotNull('redeemed_at')->count() === 1, 'Expected one redeemed grant.');

        $completion = $host->completion($callId);
        if ($completion !== [
            'status' => ExecutionStatus::SUCCEEDED->value,
            'result' => ['suggestions' => ['10:00–10:30']],
        ]) {
            throw new RuntimeException('Expected one private inbox result with only the shared suggestion.');
        }
        show('Private completion for Tom', $completion);

        $host->viewAs('Pauline');
        check($host->completion($callId) === null, 'Pauline must not see the result in the requester inbox.');

        $activity = app(Activity::class);
        foreach (['Tom', 'Pauline'] as $viewer) {
            $host->viewAs($viewer);
            $receipts = $activity->recentUse($callId);
            check(count($receipts) === 1, "Expected one receipt for {$viewer}.");
            $receipt = $receipts[0];
            check($receipt['status'] === 'granted' && ($receipt['outcome'] ?? null) === 'success'
                && $receipt['requester_id'] === 'Tom'
                && array_column($receipt['approvals'], 'approver_id') === ['Pauline']
                && array_column($receipt['approvals'], 'decision') === [ApprovalDecision::APPROVE->value],
                "Expected {$viewer}'s receipt to show Tom's request, Pauline's approval and success.");
            if ($viewer === 'Tom') {
                check(($receipt['reason'] ?? null) === REASON, 'Tom should see his recorded reason.');
            } else {
                check(! array_key_exists('reason', $receipt)
                    && array_column($receipt['targets'] ?? [], 'id') === ['pauline-calendar'], 'Pauline should see only her calendar and no reason.');
            }

            show("Private receipt for {$viewer}", $receipt);
        }
        echo 'Two-owner demo passed. One executor call after a fresh worker start.'.PHP_EOL;

        return;
    }

    check($phase === 'prepare', 'Unknown demo phase.');
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

    $definition = app(ToolRegistry::class)->get('joint_availability', '1');
    $schema = $definition->inputSchema;
    check($schema['required'] === ['date'] && array_keys($schema['properties']) === ['date']
        && ($schema['additionalProperties'] ?? null) === false, 'The tool schema must accept only the date. The reason belongs to the invocation.');
    $arguments = ['date' => $schema['properties']['date']['const']];
    $cache->put('demo.request-context', [
        'id' => $callId,
        'requester' => $context->requesterId,
        'runtime' => $context->runtimeId,
        'audience' => $context->audience,
    ]);
    $result = app(Governance::class)->call($callId, $definition->name, $definition->version, $arguments, REASON);
    check($result === ['status' => 'pending_approval', 'call_id' => $callId, 'result' => null],
        'Expected an audience-safe pending response.');
    show('Model status', $result);

    check($jobs->count() === 1, 'Expected one queued card delivery.');
    check(Artisan::call('queue:work', ['--once' => true, '--queue' => 'default', '--tries' => 1]) === 0, 'Card delivery worker failed.');
    $cards = $inbox->cards($scope, 'Pauline');
    check(count($cards) === 1 && $inbox->cards($scope, 'Tom') === [], 'Only Pauline should receive one private card.');
    $card = $cards[0];
    check(str_contains($card->message, 'Requester: Tom')
        && str_contains($card->message, REASON)
        && str_contains($card->message, 'Suggest common times for 2026-12-02'),
        'The card should describe the exact request.');
    echo 'Private approval card delivered to Pauline.'.PHP_EOL;

    $reply = new VerifiedReply($scope, 'Pauline', $card->cardId, 'demo-pauline-reply');
    check($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, $reply), 'The verified reply from Pauline was refused.');
    check($jobs->count() === 1, 'Expected one queued resume job after approval.');
    check($approvals->decide($card->approvalId, ApprovalDecision::APPROVE, $reply), 'A duplicate reply should be idempotent.');
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
