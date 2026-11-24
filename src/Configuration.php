<?php

declare(strict_types=1);

namespace Ocdejong\SharedToolGovernance;

use InvalidArgumentException;

/** @internal */
final class Configuration
{
    // Keep date arithmetic within the portable signed integer range.
    private const int MAX_DURATION = 2_147_483_647;

    public static function duration(string $setting): int
    {
        $key = 'shared-tool-governance.'.$setting;
        $value = config($key);
        if (is_string($value)) {
            $value = trim($value);
            if (ctype_digit($value)) {
                $value = ltrim($value, '0');
            }
        }

        $duration = is_int($value) || is_string($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_DURATION]])
            : false;
        if ($duration === false) {
            throw new InvalidArgumentException(sprintf(
                'Configuration value for key [%s] must be an integer from 1 to %d.',
                $key,
                self::MAX_DURATION,
            ));
        }

        return $duration;
    }
}
