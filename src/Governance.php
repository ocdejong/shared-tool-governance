<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use DomainException;
use Illuminate\Support\Facades\DB;
use Ocdejong\SharedToolGovernance\Authorization\Authorizer;
use Ocdejong\SharedToolGovernance\Contracts\TrustedInvocationContext;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Grants\GrantService;
use Ocdejong\SharedToolGovernance\Tools\ToolRegistry;

final readonly class Governance
{
    public function __construct(
        private ToolRegistry $tools,
        private TrustedInvocationContext $context,
        private Authorizer $authorizer,
        private GrantService $grants,
    ) {}

    /**
     * Call outside an open database transaction. A repeat reports the recorded
     * execution outcome or current authorization status, with a null result.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{status: string, call_id: string, result: array<string, mixed>|null}
     */
    public function call(string $id, string $tool, string $version, array $arguments, string $reason): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new DomainException('A governed call must start outside a database transaction.');
        }

        $definition = $this->tools->get($tool, $version);
        $invocation = new Invocation($id, $tool, $version, $arguments, $reason, $this->context->current(), $definition->executorId);
        $authorization = $this->authorizer->authorize($invocation);
        $token = $authorization->grantToken();
        if ($token === null) {
            $status = $authorization->executionOutcome()->value ?? $authorization->status;

            return ['status' => $status, 'call_id' => $id, 'result' => null];
        }

        $execution = $this->grants->redeem($token, $invocation);

        return ['status' => $execution->status->value, 'call_id' => $id, 'result' => $execution->result];
    }
}
