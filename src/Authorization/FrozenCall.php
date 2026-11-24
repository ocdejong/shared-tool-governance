<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance\Authorization;

use InvalidArgumentException;
use JsonException;
use Ocdejong\SharedToolGovernance\Data\Invocation;
use Ocdejong\SharedToolGovernance\Data\ResourceReference;
use Ocdejong\SharedToolGovernance\Data\ToolDefinition;

final readonly class FrozenCall
{
    /** @param list<ResourceReference> $targets */
    public function __construct(
        public Invocation $call,
        public ToolDefinition $tool,
        public array $targets,
    ) {}

    /** @return array{arguments_json: string, effect: string, targets: list<array<string, string|null>>} */
    public function inputs(): array
    {
        return [
            'arguments_json' => self::encode($this->call->arguments),
            'effect' => $this->tool->effect,
            'targets' => array_map(get_object_vars(...), $this->targets),
        ];
    }

    public function fingerprint(): string
    {
        $fields = get_object_vars($this->call);
        $context = $this->call->context;
        $fields['context'] = get_object_vars($context);
        // Stored calls hash this key and field order.
        $fields['context']['scope'] = ['hostScope' => $context->scope->hostScope, 'householdId' => $context->scope->workspaceId];
        $fields['arguments'] = self::canonicalize($this->call->arguments);
        $fields['action'] = $this->tool->actionKey;
        $fields['effect'] = $this->tool->effect;
        $fields['targets'] = $this->targets;

        return hash('sha256', self::encode($fields));
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return list<mixed>|object
     */
    private static function canonicalize(array $arguments, int $depth = 0): array|object
    {
        if ($depth >= 500) {
            throw new InvalidArgumentException('Tool arguments are nested too deeply.');
        }

        $isList = array_is_list($arguments);
        if (! $isList) {
            ksort($arguments, SORT_STRING);
        }

        foreach ($arguments as $key => $value) {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Tool arguments must be JSON values.');
            }

            if (is_array($value)) {
                $arguments[$key] = self::canonicalize($value, $depth + 1);
            }
        }

        return $isList ? array_values($arguments) : (object) $arguments;
    }

    /** @param array<array-key, mixed> $value */
    private static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('Tool call must be representable as JSON.');
        }
    }
}
