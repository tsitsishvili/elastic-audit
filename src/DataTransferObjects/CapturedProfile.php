<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final readonly class CapturedProfile
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{function: string, file: ?string, line: ?int, self_samples: int, total_samples: int}>  $hotFrames
     * @param  list<array{function: string, self_ms: float, total_ms: float, calls: ?int}>  $functionTimings
     *                                                                                                        Per-function cost for every frame the profiler saw, ordered by
     *                                                                                                        inclusive time. Unlike $hotFrames — which ranks leaves — this includes
     *                                                                                                        the callers application code usually consists of, which are rarely
     *                                                                                                        leaves themselves. Sampling drivers report estimates here.
     */
    public function __construct(
        public string $driver,
        public string $mode,
        public string $format,
        public ?float $sampleRateHz,
        public int $sampleCount,
        public array $payload,
        public array $hotFrames,
        public bool $truncated = false,
        public array $functionTimings = [],
    ) {}
}
