<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Dashboard;

use Illuminate\Support\Carbon;

/**
 * Merges the metrics, HTTP log and activity log documents of one trace into
 * rows ordered on a shared millisecond timeline.
 *
 * TraceRow = array{kind: 'transaction'|'span'|'job'|'http'|'activity', type: string, at: ?float,
 *                  offset_ms: ?float, duration_ms: ?float, doc: array<string, mixed>, children: list<TraceRow>}
 */
final class TraceTimeline
{
    /**
     * @param  list<array<string, mixed>>  $metrics  metrics docs, profiled frames included
     * @param  list<array<string, mixed>>  $httpLogs
     * @param  list<array<string, mixed>>  $activityLogs
     * @return array{start: ?float, rows: list<array<string, mixed>>, profiled: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function build(array $metrics, array $httpLogs, array $activityLogs): array
    {
        $profiled = array_values(array_filter($metrics, fn ($d) => ($d['type'] ?? null) === 'app.function.profiled'));
        usort($profiled, fn ($a, $b) => (float) ($b['duration_ms'] ?? 0) <=> (float) ($a['duration_ms'] ?? 0));

        $timeline     = array_filter($metrics, fn ($d) => ($d['type'] ?? null) !== 'app.function.profiled');
        $transactions = array_values(array_filter($timeline, fn ($d) => ($d['kind'] ?? null) === 'transaction'));
        $spans        = array_filter($timeline, fn ($d) => ($d['kind'] ?? null) !== 'transaction');

        $ats   = array_filter(array_map(self::at(...), $timeline), fn ($a) => $a !== null);
        $start = $ats === [] ? null : min($ats);

        $root = null;

        foreach ($transactions as $tx) {
            if (data_get($tx, 'trace.parent_span_id') === null) {
                $root = $tx;

                break;
            }
        }
        $root ??= self::sorted(array_map(fn ($d) => self::row($d, 'transaction', $start), $transactions))[0]['doc'] ?? null;

        // A transaction's own id is also its span id, so either field can key it.
        $txId = fn (array $d) => data_get($d, 'transaction.id') ?? data_get($d, 'trace.span_id');

        $jobs = [];

        foreach ($transactions as $tx) {
            if ($tx !== $root) {
                $jobs[$txId($tx)] = self::row($tx, 'job', $start);
            }
        }

        $top = [];

        foreach ($spans as $span) {
            $row = self::row($span, 'span', $start);
            $id  = data_get($span, 'transaction.id');

            if ($id !== null && isset($jobs[$id])) {
                $jobs[$id]['children'][] = $row;
            } else {
                $top[] = $row;
            }
        }

        foreach ($httpLogs as $doc) {
            $latency = data_get($doc, 'http.latency_ms');
            $top[]   = self::row($doc, 'http', $start, 'http', is_numeric($latency) ? (float) $latency : null);
        }

        foreach ($activityLogs as $doc) {
            $top[] = self::row($doc, 'activity', $start, 'activity', null);
        }

        foreach ($jobs as $job) {
            $job['children'] = self::sorted($job['children']);
            $top[]           = $job;
        }

        $rows = self::sorted($top);

        if ($root !== null) {
            array_unshift($rows, self::row($root, 'transaction', $start));
        }

        $counts = ['span' => 0, 'http' => 0, 'activity' => 0, 'job' => 0];

        foreach ($rows as $row) {
            foreach ([$row, ...$row['children']] as $r) {
                if (isset($counts[$r['kind']])) {
                    $counts[$r['kind']]++;
                }
            }
        }

        return ['start' => $start, 'rows' => $rows, 'profiled' => $profiled, 'counts' => $counts];
    }

    /** @param array<string, mixed> $doc */
    private static function row(array $doc, string $kind, ?float $start, ?string $type = null, ?float $duration = null): array
    {
        $at = self::at($doc);

        // An HTTP log is stamped after the response, so its @timestamp is the end of the call, not its start.
        if ($kind === 'http' && $at !== null && $duration > 0) {
            $at -= $duration;
        }

        return [
            'kind'        => $kind,
            'type'        => $type ?? (string) ($doc['type'] ?? ''),
            'at'          => $at,
            'offset_ms'   => $at !== null && $start !== null ? $at - $start : null,
            'duration_ms' => isset($doc['duration_ms']) ? (float) $doc['duration_ms'] : $duration,
            'doc'         => $doc,
            'children'    => [],
        ];
    }

    /** @param array<string, mixed> $doc */
    private static function at(array $doc): ?float
    {
        $ts = $doc['@timestamp'] ?? null;

        if (! is_string($ts) || $ts === '') {
            return null;
        }

        try {
            return (float) Carbon::parse($ts)->getPreciseTimestamp(3);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Stable sort by start time; rows without one go last. */
    private static function sorted(array $rows): array
    {
        usort($rows, fn ($a, $b) => ($a['at'] ?? INF) <=> ($b['at'] ?? INF));

        return $rows;
    }
}
