<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Ocdejong\SharedToolGovernance\Approvals\Approvals;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\CompletionHandler;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\ExecutionStatus;
use Ocdejong\SharedToolGovernance\Data\Scope;
use Ocdejong\SharedToolGovernance\Jobs\DeliverCompletion;
use Ocdejong\SharedToolGovernance\Jobs\ResumeApproval;
use Ocdejong\SharedToolGovernance\Tests\Approvals\ApprovalHost;

beforeEach(function (): void {
    expect(Artisan::call('migrate'))->toBe(0, Artisan::output());
    $this->beforeApplicationDestroyed(static function (): void {
        expect(Artisan::call('migrate:rollback'))->toBe(0, Artisan::output());
    });
    Queue::fake()->serializeAndRestore();
});

function approveForCompletion(): ResumeApproval
{
    $host = new ApprovalHost;
    $host->bind();
    expect(app(Authorizer::class)->authorize($host->call())->jsonSerialize())
        ->toBe(['status' => 'pending_approval', 'request_id' => 'visit']);
    ApprovalHost::deliverCards();
    expect($host->decideFromInbox('alice'))->toBeTrue()
        ->and($host->decideFromInbox('alice'))->toBeTrue();

    return Queue::pushed(ResumeApproval::class)->sole();
}

it('queues a committed delegated result and retries only its delivery', function (bool $timeout): void {
    $resume = approveForCompletion();
    $worker = new ApprovalHost;
    $worker->workerOnly = true;
    $worker->timeout = $timeout;
    $worker->bind();

    $status = $timeout ? ExecutionStatus::UNKNOWN : ExecutionStatus::SUCCEEDED;
    $expected = $timeout ? new ExecutionResult($status) : new ExecutionResult(
        $status, ['raw' => 'hidden result'], 'hidden provider reference',
    );
    $delivery = Mockery::mock(CompletionHandler::class);
    $delivery->shouldReceive('completed')->once()->ordered()->andThrow(new RuntimeException('Inbox unavailable.'));
    $delivery->shouldReceive('completed')->once()->ordered()->withArgs(
        function (Scope $scope, string $invocationId, ExecutionResult $result) use ($worker, $expected): bool {
            expect(DB::transactionLevel())->toBe(0)
                ->and($scope->equals($worker->context->scope))->toBeTrue()
                ->and($invocationId)->toBe('visit')
                ->and($result)->toEqual($expected);

            return true;
        },
    );
    app()->instance(CompletionHandler::class, $delivery);

    app()->call([$resume, 'handle']);
    app()->call([$resume, 'handle']);
    $completion = Queue::pushed(DeliverCompletion::class)->sole();
    if (! $completion instanceof DeliverCompletion) {
        throw new LogicException('The worker could not reload the completion job.');
    }

    $worker->workerOnly = false;
    expect(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => $status->value]);
    $worker->workerOnly = true;
    expect(fn () => app()->call([$completion, 'handle']))->toThrow(RuntimeException::class, 'Inbox unavailable.');
    app()->call([$completion, 'handle']);
    expect($worker->executions)->toHaveCount(1);
})->with(['success' => false, 'unknown' => true]);

it('does not queue a result if access is revoked before execution', function (): void {
    $resume = approveForCompletion();
    $worker = new ApprovalHost;
    $worker->deny = true;
    $worker->bind();
    app()->instance(CompletionHandler::class, Mockery::mock(CompletionHandler::class));

    app()->call([$resume, 'handle']);

    Queue::assertNotPushed(DeliverCompletion::class);
    expect($worker->executions)->toBe([])
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'revoked']);
});

it('keeps the delegated receipt if the host has no completion handler', function (): void {
    $resume = approveForCompletion();

    app()->call([$resume, 'handle']);

    Queue::assertNotPushed(DeliverCompletion::class);
    expect(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success']);
});

it('keeps the committed receipt if the completion cannot be enqueued', function (): void {
    $resume = approveForCompletion();
    $worker = new ApprovalHost;
    $worker->bind();
    app()->instance(CompletionHandler::class, Mockery::mock(CompletionHandler::class));
    Bus::partialMock()->shouldReceive('dispatch')->once()->with(Mockery::on(
        static fn (mixed $job): bool => $job instanceof DeliverCompletion
            && DB::transactionLevel() === 0
            && app(Approvals::class)->receipt('visit') === ['status' => 'granted', 'outcome' => 'success'],
    ))->andThrow(new RuntimeException('Queue unavailable.'));

    expect(fn () => app()->call([$resume, 'handle']))->toThrow(RuntimeException::class, 'Queue unavailable.');
    app()->call([$resume, 'handle']);

    expect($worker->executions)->toHaveCount(1)
        ->and(app(Approvals::class)->receipt('visit'))->toBe(['status' => 'granted', 'outcome' => 'success']);
});
