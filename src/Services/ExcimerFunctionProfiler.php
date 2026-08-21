<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Throwable;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;

final class ExcimerFunctionProfiler implements FunctionProfiler
{
    private ?object $profiler = null;

    public function __construct(
        private readonly float $periodMs = 10.1,
        private readonly int $maxDepth = 128,
        private readonly int $maxSamples = 10000,
        private readonly int $maxPayloadBytes = 2097152,
        private readonly bool $includePaths = false,
    ) {}

    public function available(): bool
    {
        return class_exists('ExcimerProfiler') && defined('EXCIMER_REAL');
    }

    public function driver(): ?string
    {
        return $this->available() ? 'excimer' : null;
    }

    public function start(): bool
    {
        if (! $this->available() || $this->profiler !== null) {
            return false;
        }

        try {
            $class    = 'ExcimerProfiler';
            $profiler = new $class;
            $profiler->setPeriod(max(0.0001, $this->periodMs / 1000));
            $profiler->setEventType((int) constant('EXCIMER_REAL'));
            $profiler->setMaxDepth(max(1, $this->maxDepth));
            $profiler->start();
            $this->profiler = $profiler;

            return true;
        } catch (Throwable) {
            $this->profiler = null;

            return false;
        }
    }

    public function stop(): ?CapturedProfile
    {
        $profiler       = $this->profiler;
        $this->profiler = null;

        if ($profiler === null) {
            return null;
        }

        try {
            $profiler->stop();
            $payload = $profiler->getLog()->getSpeedscopeData();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        [$payload, $sampleCount, $hotFrames, $truncated] = $this->normalize($payload);

        return new CapturedProfile(
            driver: 'excimer',
            mode: 'sampling',
            format: 'speedscope',
            sampleRateHz: 1000 / max(0.0001, $this->periodMs),
            sampleCount: $sampleCount,
            payload: $payload,
            hotFrames: $hotFrames,
            truncated: $truncated,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{array<string, mixed>, int, list<array{function: string, file: ?string, line: ?int, self_samples: int, total_samples: int}>, bool}
     */
    private function normalize(array $payload): array
    {
        $truncated = false;
        $frames    = is_array($payload['shared']['frames'] ?? null) ? $payload['shared']['frames'] : [];

        foreach ($frames as $index => $frame) {
            if (! is_array($frame)) {
                $frames[$index] = ['name' => 'unknown'];

                continue;
            }

            $normalized = ['name' => mb_substr((string) ($frame['name'] ?? 'unknown'), 0, 512)];

            if ($this->includePaths && isset($frame['file']) && is_string($frame['file'])) {
                $normalized['file'] = $this->relativePath($frame['file']);
            }

            if (isset($frame['line']) && is_numeric($frame['line'])) {
                $normalized['line'] = (int) $frame['line'];
            }

            $frames[$index] = $normalized;
        }

        $payload['shared']['frames'] = $frames;
        $profiles                    = is_array($payload['profiles'] ?? null) ? $payload['profiles'] : [];
        $samples                     = [];

        foreach ($profiles as $index => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileSamples = is_array($profile['samples'] ?? null) ? array_values($profile['samples']) : [];

            if (count($profileSamples) > $this->maxSamples) {
                $truncated      = true;
                $profileSamples = array_slice($profileSamples, 0, max(1, $this->maxSamples));
            }

            $profile['samples'] = $profileSamples;

            if (isset($profile['weights']) && is_array($profile['weights'])) {
                $profile['weights'] = array_slice(array_values($profile['weights']), 0, count($profileSamples));
            }

            $profiles[$index] = $profile;
            $samples          = [...$samples, ...$profileSamples];
        }

        $payload['profiles'] = $profiles;

        while ($samples !== [] && strlen((string) json_encode($payload)) > $this->maxPayloadBytes) {
            $truncated = true;

            foreach ($profiles as $index => $profile) {
                if (! is_array($profile)) {
                    continue;
                }

                $profileSamples     = is_array($profile['samples'] ?? null) ? $profile['samples'] : [];
                $profile['samples'] = array_slice($profileSamples, 0, intdiv(count($profileSamples), 2));

                if (isset($profile['weights']) && is_array($profile['weights'])) {
                    $profile['weights'] = array_slice($profile['weights'], 0, count($profile['samples']));
                }

                $profiles[$index] = $profile;
            }

            $payload['profiles'] = $profiles;
            $samples             = [];

            foreach ($profiles as $profile) {
                if (is_array($profile) && is_array($profile['samples'] ?? null)) {
                    $samples = [...$samples, ...$profile['samples']];
                }
            }
        }

        if (strlen((string) json_encode($payload)) > $this->maxPayloadBytes) {
            $truncated = true;
            $frames    = [];
            $samples   = [];
            $payload   = [
                '$schema'            => $payload['$schema'] ?? 'https://www.speedscope.app/file-format-schema.json',
                'name'               => $payload['name'] ?? 'truncated',
                'activeProfileIndex' => 0,
                'shared'             => ['frames' => []],
                'profiles'           => [],
            ];
        }

        $self  = [];
        $total = [];

        foreach ($samples as $stack) {
            if (! is_array($stack)) {
                continue;
            }

            foreach ($stack as $frameId) {
                if (is_int($frameId) || is_numeric($frameId)) {
                    $total[(int) $frameId] = ($total[(int) $frameId] ?? 0) + 1;
                }
            }

            $leaf = end($stack);

            if (is_int($leaf) || is_numeric($leaf)) {
                $self[(int) $leaf] = ($self[(int) $leaf] ?? 0) + 1;
            }
        }

        arsort($self);
        $hotFrames = [];

        foreach (array_slice($self, 0, 100, true) as $frameId => $count) {
            $frame       = is_array($frames[$frameId] ?? null) ? $frames[$frameId] : [];
            $hotFrames[] = [
                'function'      => (string) ($frame['name'] ?? 'unknown'),
                'file'          => isset($frame['file']) ? (string) $frame['file'] : null,
                'line'          => isset($frame['line']) ? (int) $frame['line'] : null,
                'self_samples'  => $count,
                'total_samples' => $total[$frameId] ?? $count,
            ];
        }

        return [$payload, count($samples), $hotFrames, $truncated];
    }

    private function relativePath(string $path): string
    {
        $base = function_exists('base_path') ? rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : '';

        return $base !== '' && str_starts_with($path, $base)
            ? mb_substr($path, strlen($base), 1024)
            : mb_substr(basename($path), 0, 1024);
    }
}
