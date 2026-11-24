<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use JsonSerializable;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class AuthorizationResult implements JsonSerializable
{
    private ?SensitiveParameterValue $token;

    public function __construct(
        public string $status,
        private ?string $requestId = null,
        #[SensitiveParameter] ?string $token = null,
    ) {
        $this->token = $token === null ? null : new SensitiveParameterValue($token);
    }

    public function grantToken(): ?string
    {
        return $this->token?->getValue();
    }

    /** @return array{status: string, request_id?: string} */
    public function jsonSerialize(): array
    {
        return $this->status === 'pending_approval' && $this->requestId !== null
            ? ['status' => $this->status, 'request_id' => $this->requestId]
            : ['status' => $this->status];
    }

    /** @return array{status: string, request_id?: string} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return array{status: string, request_id?: string} */
    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    /** @param array{status: string, request_id?: string} $data */
    public function __unserialize(array $data): void
    {
        $this->__construct($data['status'], $data['request_id'] ?? null);
    }
}
