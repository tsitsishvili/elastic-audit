<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final readonly class CapturedProfile
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{function: string, file: ?string, line: ?int, self_samples: int, total_samples: int}>  $hotFrames
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
    ) {}
}
