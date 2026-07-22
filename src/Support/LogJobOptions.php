<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final class LogJobOptions
{
    public static function tries(string $configKey, int $default = 3): int
    {
        return max(1, self::integer(config("{$configKey}.tries", $default)) ?? $default);
    }

    /**
     * @return list<int>
     */
    public static function backoff(string $configKey, array|string|null $default = [10, 30, 120]): array
    {
        $value = config("{$configKey}.backoff", $default);

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            $value = $default;
        }

        $backoff = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
            }

            $integer = self::integer($item);

            if ($integer === null || $integer < 0) {
                continue;
            }

            $backoff[] = $integer;
        }

        return $backoff !== [] ? $backoff : [10, 30, 120];
    }

    public static function timeout(string $configKey, int $default): int
    {
        return max(1, self::integer(config("{$configKey}.timeout", $default)) ?? $default);
    }

    public static function batchTimeout(string $configKey, int $default = 60): int
    {
        return max(1, self::integer(config("{$configKey}.batch_timeout", $default)) ?? $default);
    }

    private static function integer(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated === false ? null : $validated;
    }
}
