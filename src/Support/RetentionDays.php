<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use InvalidArgumentException;

final class RetentionDays
{
    public const MIN = 1;

    public const MAX = 32767;

    public static function validate(int $days): int
    {
        if ($days < self::MIN || $days > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'retention_days must be between %d and %d; %d given.',
                self::MIN,
                self::MAX,
                $days,
            ));
        }

        return $days;
    }

    public static function fromConfig(mixed $days): int
    {
        if (! is_int($days) && ! is_string($days)) {
            throw new InvalidArgumentException('retention_days must be an integer.');
        }

        $validated = filter_var($days, FILTER_VALIDATE_INT);

        if ($validated === false) {
            throw new InvalidArgumentException('retention_days must be an integer.');
        }

        return self::validate($validated);
    }

    public static function resolve(
        ?int $days,
        bool $retainForever,
        mixed $configuredDays,
        bool $configuredRetainForever,
    ): ?int {
        if ($retainForever && $days !== null) {
            throw new InvalidArgumentException('retentionDays and retainForever cannot both be set.');
        }

        if ($retainForever) {
            return null;
        }

        if ($days !== null) {
            return self::validate($days);
        }

        if ($configuredRetainForever) {
            return null;
        }

        return self::fromConfig($configuredDays);
    }
}
