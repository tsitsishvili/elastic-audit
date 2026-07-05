<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final class LogJobOptions
{
    public static function tries(string $configKey, int $default = 3): int
    {
        return max(1, (int) config("{$configKey}.tries", $default));
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
            $item = trim((string) $item);

            if (! is_numeric($item) || (int) $item < 0) {
                continue;
            }

            $backoff[] = (int) $item;
        }

        return $backoff !== [] ? $backoff : [10, 30, 120];
    }

    public static function timeout(string $configKey, int $default): int
    {
        return max(1, (int) config("{$configKey}.timeout", $default));
    }
}
