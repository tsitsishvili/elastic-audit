<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;

final class XhprofFunctionProfiler implements FunctionProfiler
{
    private bool $running = false;

    public function __construct(
        private readonly bool $captureCpu = true,
        private readonly bool $captureMemory = true,
        private readonly int $maxEdges = 10000,
        private readonly int $maxPayloadBytes = 2097152,
    ) {}

    public function available(): bool
    {
        return function_exists('xhprof_enable') && function_exists('xhprof_disable');
    }

    public function driver(): ?string
    {
        return $this->available() ? 'xhprof' : null;
    }

    public function start(): bool
    {
        if (! $this->available() || $this->running) {
            return false;
        }

        $flags = 0;

        if (defined('XHPROF_FLAGS_NO_BUILTINS')) {
            $flags |= (int) constant('XHPROF_FLAGS_NO_BUILTINS');
        }

        if ($this->captureCpu && defined('XHPROF_FLAGS_CPU')) {
            $flags |= (int) constant('XHPROF_FLAGS_CPU');
        }

        if ($this->captureMemory && defined('XHPROF_FLAGS_MEMORY')) {
            $flags |= (int) constant('XHPROF_FLAGS_MEMORY');
        }

        try {
            xhprof_enable($flags);

            return $this->running = true;
        } catch (Throwable) {
            return false;
        }
    }

    public function stop(): ?CapturedProfile
    {
        if (! $this->running) {
            return null;
        }

        $this->running = false;

        $raw = xhprof_disable();

        $edges = [];
        $hot   = [];

        foreach ($raw as $edge => $values) {
            if (! is_string($edge) || ! is_array($values)) {
                continue;
            }

            [$caller, $function] = array_pad(explode('==>', $edge, 2), 2, $edge);
            $calls               = max(1, (int) ($values['ct'] ?? 1));
            $wallMs              = max(0.0, (float) ($values['wt'] ?? 0) / 1000);
            $edges[]             = array_filter([
                'caller'       => mb_substr($caller, 0, 512),
                'function'     => mb_substr($function, 0, 512),
                'calls'        => $calls,
                'wall_ms'      => $wallMs,
                'cpu_ms'       => isset($values['cpu']) ? max(0.0, (float) $values['cpu'] / 1000) : null,
                'memory_bytes' => isset($values['mu']) ? (int) $values['mu'] : null,
            ], static fn (mixed $value): bool => $value !== null);

            $hot[$function] ??= ['self' => 0, 'total' => 0];
            $hot[$function]['self'] += $calls;
            $hot[$function]['total'] += $calls;
        }

        usort($edges, static fn (array $a, array $b): int => $b['wall_ms'] <=> $a['wall_ms']);
        $truncated = count($edges) > $this->maxEdges;
        $edges     = array_slice($edges, 0, max(1, $this->maxEdges));
        $payload   = ['edges' => $edges];

        while ($edges !== [] && strlen((string) json_encode($payload)) > $this->maxPayloadBytes) {
            $truncated = true;
            $edges     = array_slice($edges, 0, max(0, intdiv(count($edges), 2)));
            $payload   = ['edges' => $edges];
        }

        uasort($hot, static fn (array $a, array $b): int => $b['self'] <=> $a['self']);
        $hotFrames = [];

        foreach (array_slice($hot, 0, 100, true) as $function => $counts) {
            $hotFrames[] = [
                'function'      => mb_substr((string) $function, 0, 512),
                'file'          => null,
                'line'          => null,
                'self_samples'  => $counts['self'],
                'total_samples' => $counts['total'],
            ];
        }

        return new CapturedProfile(
            driver: 'xhprof',
            mode: 'instrumentation',
            format: 'xhprof-edges',
            sampleRateHz: null,
            sampleCount: array_sum(array_column($edges, 'calls')),
            payload: $payload,
            hotFrames: $hotFrames,
            truncated: $truncated,
        );
    }
}
