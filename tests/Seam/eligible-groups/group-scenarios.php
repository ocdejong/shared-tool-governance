<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Approvals\ApprovalDecision;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Contracts\ApproverPolicy;
use Ocdejong\SharedToolGovernance\Contracts\CompletionHandler;
use Ocdejong\SharedToolGovernance\Contracts\GroupApprovalChannel;
use Ocdejong\SharedToolGovernance\Data\ApprovalMode;
use Ocdejong\SharedToolGovernance\Data\ApprovalRule;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\OwnerReference;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Outbox\ApprovalWork;

function groupScenario(EligibleGroupsConsumer $host, string $phase): void
{
    $scope = $host->current()->scope;
    $chat = new GroupChat($scope, config()->string('app.key'));
    app()->instance(GroupApprovalChannel::class, $chat);
    app()->instance(ApproverPolicy::class, new class implements ApproverPolicy
    {
        public function approvalRuleFor(Scope $scope, ResourceReference $resource, OwnerReference $owner): ?ApprovalRule
        {
            $parents = ['Olivier', 'Pauline'];

            return match ($resource->id) {
                'approve-first', 'decline-first', 'opposite', 'duplicate', 'retry', 'audience',
                'unsafe-preview', 'unauthorized-chat', 'disclosure-lost', 'overlap' => new ApprovalRule(ApprovalMode::ANY, $parents),
                'lost-voter' => new ApprovalRule(ApprovalMode::ANY, is_file(storage_path('authority-changed')) ? ['Pauline'] : $parents),
                'whole' => new ApprovalRule(ApprovalMode::ANY, [...$parents, 'child']),
                'named-olivier', 'named-pauline' => new ApprovalRule(ApprovalMode::ANY, [$owner->id]),
                'overlap-personal' => new ApprovalRule(ApprovalMode::ANY, ['Olivier', 'child']),
                'empty' => new ApprovalRule(ApprovalMode::ANY, []),
                'unanimous', 'unanimous-decline' => new ApprovalRule(ApprovalMode::ALL, $parents),
                default => null,
            };
        }
    });
    app()->instance(CompletionHandler::class, new class implements CompletionHandler
    {
        public function completed(Scope $scope, string $invocationId, ExecutionResult $result): void
        {
            $receipt = app(Approvals::class)->receipt($invocationId);
            check($receipt === ['status' => 'granted', 'outcome' => 'success'], 'Completion was sent to an unauthorized audience');
            check($result->result === ['time' => '10:00'], 'Completion lost the private result');
            check(file_put_contents(storage_path('private-completions'), $invocationId.PHP_EOL, FILE_APPEND | LOCK_EX) !== false, 'Could not deliver the private completion');
        }
    });

    switch ($phase) {
        case 'prepare-groups':
            foreach (['approve-first', 'decline-first', 'whole', 'opposite', 'duplicate', 'retry', 'lost-voter',
                'audience', 'unsafe-preview', 'unauthorized-chat', 'disclosure-lost', 'empty', 'unanimous', 'unanimous-decline'] as $id) {
                $host->request($id, [$id]);
            }
            $host->request('overlap', ['overlap', 'overlap-personal']);
            $host->request('named', ['named-olivier', 'named-pauline']);
            check(touch(storage_path('disclosure-revoked')), 'Could not change the chat audience before delivery');
            break;
        case 'group-delivery-worker':
            worker(tries: 2);
            break;
        case 'group-replies':
            groupReplies($host, $chat);
            break;
        case 'race-group-replies':
            groupReplyRaces($chat);
            break;
        case 'race-resumers':
            $rowId = (int) DB::table('governance_invocations')->where('invocation_id', 'duplicate')->value('id');
            Bus::dispatch((new ResumeApproval($rowId))->onQueue('resumer-a'));
            Bus::dispatch((new ResumeApproval($rowId))->onQueue('resumer-b'));
            concurrentAtInvocation(static function (bool $child): string {
                worker($child ? 'resumer-b' : 'resumer-a', tries: 3);

                return 'worked';
            });
            check(app(Approvals::class)->receipt('duplicate') === ['status' => 'granted', 'outcome' => 'success'], 'Both racing workers left the call unexecuted');
            break;
        case 'group-worker':
            app(ApprovalWork::class)->recover();
            worker();
            break;
        case 'verify-groups':
            verifyGroups($chat);
            break;
        default:
            throw new InvalidArgumentException('Unknown group scenario');
    }
}

function groupReplies(EligibleGroupsConsumer $host, GroupChat $chat): void
{
    $approvals = app(Approvals::class);
    $approve = ApprovalDecision::APPROVE;
    $decline = ApprovalDecision::DECLINE;
    foreach (['unsafe-preview', 'unauthorized-chat', 'disclosure-lost'] as $id) {
        check($approvals->receipt($id) === ['status' => 'revoked'], "$id lost disclosure without revocation");
    }
    check($approvals->receipt('empty') === ['status' => 'pending_approval'], 'Empty group did not stay pending');
    foreach (['governance_approvals', 'governance_outbox_messages'] as $table) {
        check(! DB::table($table)->where('invocation_row_id', DB::table('governance_invocations')->select('id')
            ->where('invocation_id', 'empty'))->exists(), "Empty group staged $table");
    }

    [, $retryCard] = $chat->card('retry');
    check(file_get_contents(storage_path('retried-card')) === $retryCard, 'A delivery retry did not return the original card');
    check($chat->receive($chat->event('retry', 'Pauline', $approve))?->accepted === true, 'Retried chat card could not be answered');

    $validEvent = $chat->event('approve-first', 'Olivier', $approve);
    check($chat->resolve($validEvent)?->senderId === 'Olivier', 'The adapter could not verify the human sender');
    check($chat->receive('forged'.$validEvent) === null, 'An unverified event reached the vote');
    check($chat->receive($chat->event('approve-first', 'Olivier', $approve, ['human' => false])) === null, 'An automated sender reached the vote');
    foreach ([
        ['sender' => 'child'], ['sender' => 'outsider'], ['workspace' => 'another-team'], ['chat' => 'another-chat'],
        ['card' => $retryCard], ['chat' => null], ['delivery' => $chat->card('retry')[0]],
    ] as $changes) {
        check($chat->receive($chat->event('approve-first', 'Olivier', $approve, $changes))?->status === 'refused', 'A reply outside the frozen prompt was accepted');
        check($approvals->receipt('approve-first') === ['status' => 'pending_approval'], 'An invalid reply closed the vote');
    }

    check($chat->receive($validEvent)?->accepted === true, 'Olivier could not approve first');
    $late = $chat->receive($chat->event('approve-first', 'Pauline', $decline));
    check($late?->decision === $approve && ! $late->accepted, 'A late decline did not return the recorded approval');
    check($chat->receive($chat->event('decline-first', 'Pauline', $decline))?->accepted === true, 'Pauline could not decline first');
    $late = $chat->receive($chat->event('decline-first', 'Olivier', $approve));
    check($late?->decision === $decline && ! $late->accepted, 'A late approval did not return the recorded decline');
    check($chat->receive($chat->event('whole', 'child', $approve))?->accepted === true, 'The whole-group rule excluded a group member');

    check($chat->receive($chat->event('overlap', 'Olivier', $approve))?->accepted === true, 'The first group could not approve');
    check($approvals->receipt('overlap') === ['status' => 'pending_approval'], 'One vote approved the whole call');
    check($chat->receive($chat->event('overlap-personal', 'Olivier', $approve))?->status === 'refused', 'One human voted for two requirements');
    check($chat->receive($chat->event('overlap-personal', 'child', $approve))?->accepted === true, 'A distinct eligible voter could not settle the remaining requirement');

    check($chat->receive($chat->event('named-olivier', 'Pauline', $approve))?->status === 'refused', "Pauline could vote on Olivier's card");
    check($chat->receive($chat->event('named-olivier', 'Olivier', $approve))?->accepted === true, 'Olivier could not consent for himself');
    check($chat->receive($chat->event('named-pauline', 'Olivier', $approve))?->status === 'refused', "Olivier could vote on Pauline's card");
    check($approvals->receipt('named') === ['status' => 'pending_approval'], 'Olivier also consented for Pauline');
    check($chat->receive($chat->event('named-pauline', 'Pauline', $approve))?->accepted === true, 'Pauline could not consent for herself');
    foreach (['unanimous' => $approve, 'unanimous-decline' => $decline] as $id => $last) {
        check($host->reply($id, 'Olivier', $approve)->status === 'recorded', 'Private all did not record its first vote');
        check($approvals->receipt($id) === ['status' => 'pending_approval'], 'Private all did not wait for its final voter');
        check($host->reply($id, 'Pauline', $last)->decision === $last, 'Private all did not settle on the final vote');
    }

    check($chat->receive($chat->event('lost-voter', 'Olivier', $approve))?->accepted === true, 'Could not record the vote before eligibility changed');
    check($chat->receive($chat->event('audience', 'Pauline', $approve))?->accepted === true, 'Could not record the vote before the audience changed');
    check(touch(storage_path('authority-changed')), 'Could not change the live authority');
}

function groupReplyRaces(GroupChat $chat): void
{
    foreach (['opposite', 'duplicate'] as $id) {
        $first = $chat->event($id, 'Olivier', ApprovalDecision::APPROVE);
        $other = $id === 'duplicate' ? $first : $chat->event($id, 'Pauline', ApprovalDecision::DECLINE);
        [$local, $remote] = concurrentAtInvocation(static function (bool $child) use ($chat, $first, $other): string {
            $reply = $chat->receive($child ? $other : $first);
            if ($reply?->status !== 'settled' || $reply->decision === null) {
                throw new RuntimeException('A racing group reply did not observe a settlement');
            }

            return $reply->decision->value.':'.(int) $reply->accepted;
        });
        [$winner, $accepted] = explode(':', $local);
        check($remote === $winner.':'.(int) ($id === 'duplicate' || $accepted === '0'), 'Concurrent replies did not agree on the first decision');
        if ($id === 'opposite') {
            check(file_put_contents(storage_path('opposite-winner'), $winner) !== false, 'Could not persist the winning decision');
        }
    }
}

/**
 * @param  callable(bool):string  $run
 * @return array{string, string}
 */
function concurrentAtInvocation(callable $run): array
{
    DB::purge();
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        throw new RuntimeException('Could not create the rendezvous sockets');
    }
    $pid = pcntl_fork();
    check($pid !== -1, 'Could not start a second process');
    $child = $pid === 0;
    $socket = $sockets[$child ? 1 : 0];
    fclose($sockets[$child ? 0 : 1]);
    stream_set_timeout($socket, 10);

    $arrived = false;
    DB::connection()->beforeExecuting(static function (string $query) use ($socket, &$arrived): void {
        if ($arrived || ! str_contains($query, '"governance_invocations"')) {
            return;
        }

        $arrived = true;
        check(fwrite($socket, 'R') === 1 && fread($socket, 1) === 'R', 'Both processes must reach the invocation together');
    });
    $peer = false;
    $status = 0;
    try {
        $result = $run($child);
        check($arrived, 'The process finished without reaching the invocation');
        if ($child) {
            check(fwrite($socket, $result.PHP_EOL) !== false, 'Could not return the second process result');
        }
    } finally {
        if (! $child) {
            $peer = fgets($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            DB::purge();
        }
    }
    if ($child) {
        fclose($socket);
        exit(0);
    }
    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! is_string($peer)) {
        throw new RuntimeException('The second process failed');
    }

    return [$result, trim($peer)];
}

function verifyGroups(GroupChat $chat): void
{
    $expected = ['approve-first', 'duplicate', 'named', 'overlap', 'retry', 'unanimous', 'whole'];
    if (file_get_contents(storage_path('opposite-winner')) === ApprovalDecision::APPROVE->value) {
        $expected[] = 'opposite';
    }
    $approvals = app(Approvals::class);
    foreach ($expected as $id) {
        check($approvals->receipt($id) === ['status' => 'granted', 'outcome' => 'success'], "$id did not complete");
    }
    foreach (['decline-first', 'unanimous-decline', ...(! in_array('opposite', $expected, true) ? ['opposite'] : [])] as $id) {
        check($approvals->receipt($id) === ['status' => 'declined'], "$id did not close on its first decline");
    }
    foreach (['lost-voter', 'audience'] as $id) {
        check($approvals->receipt($id) === ['status' => 'revoked'], "$id executed after losing authority");
    }
    $late = $chat->receive($chat->event('approve-first', 'Pauline', ApprovalDecision::DECLINE));
    check($late?->decision === ApprovalDecision::APPROVE && ! $late->accepted, 'The settled decision did not survive completion');
    $late = $chat->receive($chat->event('decline-first', 'Olivier', ApprovalDecision::APPROVE));
    check($late?->decision === ApprovalDecision::DECLINE && ! $late->accepted, 'The decline did not survive worker restart');
    $executions = lines('executions');
    $completions = lines('private-completions');
    sort($expected);
    sort($executions);
    sort($completions);
    $allExecutions = [...$expected, 'separate', 'single'];
    sort($allExecutions);
    check($executions === $allExecutions, 'The worker did not execute exactly the approved calls');
    check($completions === $expected, 'Authorized completions were missing or repeated');
    check(DB::table('governance_grants')->count() === count($allExecutions), 'An unexpected grant was issued');
    check(count(glob(storage_path('group-*.json')) ?: []) === 12, 'Unexpected cards reached the group');
    foreach (['approve-first', 'decline-first', 'opposite', 'duplicate'] as $id) {
        $votes = DB::table('governance_audit_events')
            ->join('governance_invocations', 'governance_invocations.id', '=', 'governance_audit_events.invocation_row_id')
            ->where('invocation_id', $id)->where('event', 'approval_decided')->count();
        check($votes === 1, "$id recorded more than one vote");
    }
}
