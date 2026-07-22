<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

final class CaptureSampling
{
    /**
     * Whether HTTP log capture should run for this event: the subsystem must
     * be enabled and the event must pass the configured sample-rate roll.
     * Shared by the outgoing middleware and the incoming logger so both
     * directions sample identically.
     */
    public static function shouldCapture(): bool
    {
        if (! config('http_logs.enabled', false)) {
            return false;
        }

        $sampleRate = (float) config('http_logs.sample_rate', 1.0);

        return ! ($sampleRate < 1.0 && (float) mt_rand() / mt_getrandmax() >= $sampleRate);
    }
}
