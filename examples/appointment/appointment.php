<?php

declare(strict_types=1);

use App\Providers\AppointmentServiceProvider as Host;
use App\Providers\LocalApprovalChannel as LocalChannel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Activity\Activity;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Data\Verdict;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryMode;
use Ocdejong\SharedToolGovernance\Discovery\DiscoveryTarget;
use Ocdejong\SharedToolGovernance\Discovery\ToolDiscovery;
use Ocdejong\SharedToolGovernance\Governance;
use Ocdejong\SharedToolGovernance\GovernanceServiceProvider;
use Ocdejong\SharedToolGovernance\Rules\ResourceRules;

require __DIR__.'/vendor/autoload.php';

const REQUESTS = [
    'group-approve' => Host::SLOT,
    'group-decline' => Host::SLOT,
    'named-owners' => Host::PAIRED,
    'all-voters' => Host::UNANIMOUS,
];

/** @phpstan-assert true $condition */
function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === 'install') {
    $path = __DIR__.'/bootstrap/providers.php';
    $providers = require $path;
    if (! in_array(Host::class, $providers, true)) {
        $providers[] = Host::class;
        check(file_put_contents($path, "<?php\n\nreturn ".var_export($providers, true).";\n") !== false, 'Could not register the appointment provider.');
    }

    exit(0);
}

try {
    $app = require __DIR__.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    check($app->getProvider(GovernanceServiceProvider::class) !== null, 'Package discovery failed.');
    check($app->configurationIsCached() && config('shared-tool-governance') !== null, 'Expected cached package config.');
    check(config('queue.default') === 'database' && config('cache.default') === 'database', 'Expected persistent host queue and cache.');

    match ($argv[1] ?? '') {
        'prepare' => prepare(),
        'reply' => reply(),
        'verify' => verify(),
        default => throw new InvalidArgumentException('Use install, prepare, reply or verify.'),
    };
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}

/** @return array{status: string, call_id: string, result: array<string, mixed>|null} */
function reschedule(string $id, string $appointment): array
{
    return app(Governance::class)->call(
        $id,
        'reschedule',
        '1',
        ['appointment' => $appointment, 'time' => Host::TIME],
        'Move the fixture appointment.',
    );
}

function prepare(): void
{
    $host = app(Host::class);
    $scope = $host->current()->scope;
    [$resource] = Host::targets(Host::DIRECT);
    $owner = $host->ownerOf($scope, $resource);
    check($owner !== null, 'The fixture appointment has no owner.');

    Cache::put('appointment.executions.default-deny', 0);
    check(reschedule('default-deny', Host::DIRECT) === ['status' => 'denied', 'call_id' => 'default-deny', 'result' => null]
        && Cache::get('appointment.executions.default-deny') === 0, 'An unconfigured tool must default to deny.');

    $rules = app(ResourceRules::class);
    $allowed = $rules->setRules($scope, $resource, 0, ['rules' => [Host::ACTION => Verdict::ALLOW]]);
    $discovered = app(ToolDiscovery::class)->discover($scope, [new DiscoveryTarget($owner, $resource)], DiscoveryMode::MATCH);
    check(array_keys($discovered->actions) === [Host::ACTION]
        && array_column($discovered->actions[Host::ACTION], 'verdict') === [Verdict::ALLOW],
        'Discovery must find the one registered and allowed tool.');

    Cache::put('appointment.executions.first-reschedule', 0);
    check(reschedule('first-reschedule', Host::DIRECT) === [
        'status' => 'success', 'call_id' => 'first-reschedule', 'result' => ['time' => Host::TIME],
    ] && Cache::get('appointment.executions.first-reschedule') === 1, 'Explicit allow must execute once.');
    echo "Default deny, discovery and explicit allow passed.\n";

    $delegated = ['rules' => [Host::ACTION => Verdict::DELEGATE]];
    $rules->setRules($scope, $resource, $allowed->revision, $delegated);
    [, $paulineSlot] = Host::targets(Host::PAIRED);
    $rules->setRules($scope, Host::targets(Host::SLOT)[0], 0, $delegated);
    $rules->setRules($scope, Host::targets(Host::UNANIMOUS)[0], 0, $delegated);
    $host->viewer = 'Pauline';
    $rules->setRules($scope, $paulineSlot, 0, $delegated);

    $host->viewer = 'scheduler';
    foreach (REQUESTS as $id => $appointment) {
        Cache::put('appointment.request.'.$id, $host->viewer);
        Cache::put('appointment.executions.'.$id, 0);
        check(reschedule($id, $appointment) === ['status' => 'pending_approval', 'call_id' => $id, 'result' => null],
            "$id should await approval.");
    }
}

function reply(): void
{
    $host = app(Host::class);
    $host->viewer = 'scheduler';
    $approvals = app(Approvals::class);
    $channel = app(LocalChannel::class);
    $approve = ApprovalDecision::APPROVE;
    $decline = ApprovalDecision::DECLINE;
    $chat = LocalChannel::CHAT;
    $groupCards = $channel->cards($chat);
    check(count($groupCards) === 2
        && array_diff(LocalChannel::PREVIEWS, array_column($groupCards, 'preview')) === []
        && $channel->cards('scheduler') === [] && $channel->cards('observer') === [],
        'Only the approved group preview should reach the chat.');
    $byPreview = array_flip(array_map(static fn (array $card) => $card['preview'] ?? '', $groupCards));
    $approveCard = $byPreview[LocalChannel::PREVIEWS['group-approve']];
    $declineCard = $byPreview[LocalChannel::PREVIEWS['group-decline']];

    check($channel->reply('observer', $chat, $approveCard, $approve)->status === 'refused'
        && $approvals->receipt('group-approve') === ['status' => 'pending_approval'],
        'A chat observer cannot vote.');
    check($channel->reply('Pauline', $chat, $approveCard, $approve)->accepted, 'Pauline can decide the group request herself.');
    $lateDecline = $channel->reply('Olivier', $chat, $approveCard, $decline);
    check($lateDecline->status === 'settled' && $lateDecline->decision === $approve && ! $lateDecline->accepted,
        'A late decline should report the earlier approval.');
    check($channel->reply('Olivier', $chat, $declineCard, $decline)->accepted, 'Olivier can decline a group request.');
    $lateApproval = $channel->reply('Pauline', $chat, $declineCard, $approve);
    check($lateApproval->status === 'settled' && $lateApproval->decision === $decline && ! $lateApproval->accepted
        && $approvals->receipt('group-decline') === ['status' => 'declined'],
        'A late approval should report the decline and leave it settled.');

    $namedOlivier = privateCard($channel, 'Olivier', Host::DIRECT);
    $namedPauline = privateCard($channel, 'Pauline', 'pauline-slot');
    check($channel->reply('Olivier', 'Olivier', $namedOlivier, $approve)->accepted
        && $channel->reply('Olivier', 'Pauline', $namedPauline, $approve)->status === 'refused'
        && $approvals->receipt('named-owners') === ['status' => 'pending_approval'],
        "Olivier's approval cannot stand in for Pauline's.");
    check($channel->reply('Pauline', 'Pauline', $namedPauline, $approve)->accepted, 'Pauline must consent for her own appointment.');

    check($channel->reply('Olivier', 'Olivier', privateCard($channel, 'Olivier', Host::UNANIMOUS), $approve)->accepted
        && $approvals->receipt('all-voters') === ['status' => 'pending_approval'], 'An all rule must wait for the second voter.');
    check($channel->reply('Pauline', 'Pauline', privateCard($channel, 'Pauline', Host::UNANIMOUS), $approve)->accepted,
        'Both eligible voters must be able to approve an all rule.');

    foreach (['group-approve', 'named-owners', 'all-voters'] as $id) {
        check($approvals->receipt($id) === ['status' => 'approved']
            && Cache::get('appointment.executions.'.$id) === 0, "$id must wait for the next worker.");
    }
    echo "Verified replies passed. First group decisions held and named owners consented separately.\n";
}

function privateCard(LocalChannel $channel, string $recipient, string $appointment): string
{
    $cards = array_filter($channel->cards($recipient), static fn (array $card) => str_contains($card['preview'] ?? '', 'Move '.$appointment.' to '.Host::TIME));
    $id = array_key_first($cards);
    check(count($cards) === 1 && is_string($id), "Expected one private card for $recipient and $appointment.");

    return $id;
}

function verify(): void
{
    $host = app(Host::class);
    $host->viewer = 'scheduler';
    $approvals = app(Approvals::class);
    check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'A worker left queued or failed jobs.');
    foreach (REQUESTS as $id => $appointment) {
        $declined = $id === 'group-decline';
        $receipt = $declined ? ['status' => 'declined'] : ['status' => 'granted', 'outcome' => 'success'];
        $result = $declined ? null : ['time' => Host::TIME];
        check(Cache::get('appointment.executions.'.$id) === ($declined ? 0 : 1)
            && $approvals->receipt($id) === $receipt
            && Cache::get('appointment.result.scheduler.'.$id) === $result,
            "$id has the wrong execution count, receipt or result.");
        foreach (['Olivier', 'Pauline', 'observer', LocalChannel::CHAT] as $other) {
            check(! Cache::has('appointment.result.'.$other.'.'.$id), "$id leaked a private result.");
        }
        foreach (Host::targets($appointment) as $target) {
            check(Cache::get('appointment.time.'.$target->id) === Host::TIME, 'The approved change is missing.');
        }
    }
    $declinedRow = DB::table('governance_invocations')->where('invocation_id', 'group-decline')->value('id');
    check($declinedRow !== null && ! DB::table('governance_grants')->where('invocation_row_id', $declinedRow)->exists(),
        'The declined call cannot issue a grant.');
    $activity = app(Activity::class);
    $winningVotes = ['group-approve' => ['Pauline', 'approve'], 'group-decline' => ['Olivier', 'decline']];
    foreach ($winningVotes as $id => [$voter, $decision]) {
        [$receipt] = $activity->recentUse($id);
        check(array_column($receipt['approvals'], 'decision', 'approver_id') === [$voter => $decision],
            "$id must record only the first group decision.");
    }
    foreach (['Olivier' => Host::DIRECT, 'Pauline' => 'pauline-slot'] as $viewer => $slot) {
        $host->viewer = $viewer;
        [$receipt] = $activity->recentUse('named-owners');
        check(array_column($receipt['targets'] ?? [], 'id') === [$slot]
            && array_column($receipt['approvals'], 'approver_id') === [$viewer],
            "$viewer should receive only their own target and decision in the participant receipt.");
    }
    echo "Fresh workers executed each approved call once and delivered only the authorized private results.\n";
}
