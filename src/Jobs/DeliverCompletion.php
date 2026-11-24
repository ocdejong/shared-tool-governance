<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Ocdejong\SharedToolGovernance\Contracts\CompletionHandler;
use Ocdejong\SharedToolGovernance\Data\ExecutionResult;
use Ocdejong\SharedToolGovernance\Data\Scope;

/** @internal */
final class DeliverCompletion implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Scope $scope,
        public readonly string $invocationId,
        public readonly ExecutionResult $result,
    ) {}

    public function handle(CompletionHandler $handler): void
    {
        $handler->completed($this->scope, $this->invocationId, $this->result);
    }
}
