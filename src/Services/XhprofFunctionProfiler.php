<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\Support\ProfileSourcePath;

final class XhprofFunctionProfiler implements FunctionProfiler
{
    private bool $running = false;

    public function __construct(
        private readonly bool $captureCpu = true,
        private readonly bool $captureMemory = true,
        private readonly int $maxEdges = 10000,
        private readonly int $maxPayloadBytes = 2097152,
        private readonly bool $includePaths = false,
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

        return $this->capture((array) xhprof_disable());
    }

    /** @param array<mixed> $raw XHProf's caller==>callee edge map. */
    private function capture(array $raw): CapturedProfile
    {
        $edges   = [];
        $timings = [];
        $childMs = [];

        foreach ($raw as $edge => $values) {
            if (! is_string($edge) || ! is_array($values)) {
                continue;
            }

            $isRoot              = ! str_contains($edge, '==>');
            [$caller, $function] = array_pad(explode('==>', $edge, 2), 2, $edge);
            // Closure names carry their declaring file on PHP 8.4 and later.
            $caller   = ProfileSourcePath::sanitizeName($caller, $this->includePaths);
            $function = ProfileSourcePath::sanitizeName($function, $this->includePaths);
            $calls    = max(1, (int) ($values['ct'] ?? 1));
            $wallMs   = max(0.0, (float) ($values['wt'] ?? 0) / 1000);
            $edges[]  = array_filter([
                'caller'       => mb_substr($caller, 0, 512),
                'function'     => mb_substr($function, 0, 512),
                'calls'        => $calls,
                'wall_ms'      => $wallMs,
                'cpu_ms'       => isset($values['cpu']) ? max(0.0, (float) $values['cpu'] / 1000) : null,
                'memory_bytes' => isset($values['mu']) ? (int) $values['mu'] : null,
            ], static fn (mixed $value): bool => $value !== null);

            // XHProf measures wall time per caller/callee edge, so summing a
            // function's inbound edges gives its real inclusive cost, and its
            // outbound edges give the time it spent in callees.
            $timings[$function] ??= ['self_ms' => 0.0, 'total_ms' => 0.0, 'calls' => 0];
            $timings[$function]['total_ms'] += $wallMs;
            $timings[$function]['calls'] += $calls;

            if (! $isRoot) {
                $childMs[$caller] = ($childMs[$caller] ?? 0.0) + $wallMs;
            }
        }

        foreach ($timings as $function => $timing) {
            $timings[$function]['self_ms'] = max(0.0, $timing['total_ms'] - ($childMs[$function] ?? 0.0));
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

        // Instrumentation has no samples; hot frames carry exclusive and
        // inclusive wall time in microseconds, ranked by exclusive time.
        $bySelf = $timings;
        uasort($bySelf, static fn (array $a, array $b): int => $b['self_ms'] <=> $a['self_ms']);
        $hotFrames = [];

        foreach (array_slice($bySelf, 0, 100, true) as $function => $counts) {
            $hotFrames[] = [
                'function'      => mb_substr((string) $function, 0, 512),
                'file'          => null,
                'line'          => null,
                'self_samples'  => (int) round($counts['self_ms'] * 1000),
                'total_samples' => (int) round($counts['total_ms'] * 1000),
            ];
        }

        uasort($timings, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
        $functionTimings = [];

        foreach (array_slice($timings, 0, 200, true) as $function => $counts) {
            $functionTimings[] = [
                'function' => mb_substr((string) $function, 0, 512),
                'self_ms'  => round($counts['self_ms'], 3),
                'total_ms' => round($counts['total_ms'], 3),
                'calls'    => $counts['calls'],
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
            functionTimings: $functionTimings,
        );
    }
}
