<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Dashboard\TraceTimeline;

class TraceTimelineTest extends TestCase
{
    private const T = '2026-10-26T17:25:41.';

    public function test_root_transaction_is_first_and_rows_are_in_time_order(): void
    {
        $out = TraceTimeline::build([
            $this->span('s2', 'root', '640'),
            $this->span('s1', 'root', '630'),
            $this->tx('root', '630'),
        ], [], []);

        $this->assertSame(['root', 's1', 's2'], array_map(fn ($r) => $r['doc']['name'], $out['rows']));
        $this->assertSame('transaction', $out['rows'][0]['kind']);
        $this->assertSame(0.0, $out['rows'][0]['offset_ms']);
        $this->assertSame(10.0, $out['rows'][2]['offset_ms']);
    }

    public function test_audit_rows_are_interleaved_by_millisecond_timestamp(): void
    {
        $out = TraceTimeline::build(
            [$this->tx('root', '600'), $this->span('a', 'root', '618'), $this->span('b', 'root', '625')],
            [],
            [$this->activity('620')],
        );

        $this->assertSame(['transaction', 'span', 'activity', 'span'], array_column($out['rows'], 'kind'));
    }

    public function test_job_transactions_collapse_their_own_spans(): void
    {
        $out = TraceTimeline::build([
            $this->tx('root', '600'),
            $this->span('r1', 'root', '610'),
            $this->tx('job1', '650', 'r1', 'queue.job'),
            $this->span('j2', 'job1', '670'),
            $this->span('j1', 'job1', '660'),
        ], [], []);

        $this->assertSame(['transaction', 'span', 'job'], array_column($out['rows'], 'kind'));
        $job = $out['rows'][2];
        $this->assertSame(['j1', 'j2'], array_map(fn ($r) => $r['doc']['name'], $job['children']));
        $this->assertSame(60.0, $job['children'][0]['offset_ms']);
    }

    public function test_profiled_frames_are_kept_out_of_rows(): void
    {
        $out = TraceTimeline::build([
            $this->tx('root', '600'),
            $this->span('p1', 'root', '601', 'app.function.profiled', ['duration_ms' => 1.0]),
            $this->span('p2', 'root', '602', 'app.function.profiled', ['duration_ms' => 9.0]),
        ], [], []);

        $this->assertCount(1, $out['rows']);
        $this->assertSame(['p2', 'p1'], array_map(fn ($d) => $d['name'], $out['profiled']));
    }

    public function test_without_metrics_rows_have_no_offsets(): void
    {
        $out = TraceTimeline::build([], [$this->http('600')], [$this->activity('610')]);

        $this->assertNull($out['start']);
        $this->assertCount(2, $out['rows']);
        $this->assertSame([null, null], array_column($out['rows'], 'offset_ms'));
        $this->assertSame(12.5, $out['rows'][0]['duration_ms']);
        $this->assertNull($out['rows'][1]['duration_ms']);
    }

    public function test_audit_row_before_the_trace_start_gets_a_negative_offset(): void
    {
        $out = TraceTimeline::build([$this->tx('root', '600')], [], [$this->activity('597')]);

        $this->assertCount(2, $out['rows']);
        $this->assertSame('transaction', $out['rows'][0]['kind']);
        $this->assertSame(-3.0, $out['rows'][1]['offset_ms']);
    }

    public function test_http_rows_start_at_their_timestamp_minus_latency(): void
    {
        // An HTTP log is stamped after the response, so its row starts latency_ms earlier.
        $http = ['event_id' => 'h', '@timestamp' => self::T.'803Z', 'http' => ['latency_ms' => 192], 'trace' => ['id' => 't1']];

        $out = TraceTimeline::build([$this->tx('root', '600'), $this->span('s', 'root', '612')], [$http], []);

        $this->assertSame(['transaction', 'http', 'span'], array_column($out['rows'], 'kind'));
        $this->assertSame(11.0, $out['rows'][1]['offset_ms']);
        $this->assertSame(192.0, $out['rows'][1]['duration_ms']);
    }

    public function test_http_row_keeps_a_zero_latency_and_its_timestamp(): void
    {
        $http = ['event_id' => 'h', '@timestamp' => self::T.'610Z', 'http' => ['latency_ms' => 0], 'trace' => ['id' => 't1']];

        $out = TraceTimeline::build([$this->tx('root', '600')], [$http], []);

        $this->assertSame(10.0, $out['rows'][1]['offset_ms']);
        $this->assertSame(0.0, $out['rows'][1]['duration_ms']);
    }

    public function test_counts_per_kind(): void
    {
        $out = TraceTimeline::build(
            [
                $this->tx('root', '600'), $this->span('a', 'root', '601'),
                $this->tx('job1', '650', 'a', 'queue.job'), $this->span('j', 'job1', '660'),
                $this->span('orphan', 'gone', '670'),
            ],
            [$this->http('602')],
            [$this->activity('603'), $this->activity('604')],
        );

        $this->assertSame(['span' => 3, 'http' => 1, 'activity' => 2, 'job' => 1], $out['counts']);
    }

    /** @param array<string, mixed> $extra */
    private function tx(string $id, string $ms, ?string $parent = null, string $type = 'http.server', array $extra = []): array
    {
        return array_merge([
            'kind'        => 'transaction', 'type' => $type, 'name' => $id, 'duration_ms' => 100.0,
            '@timestamp'  => self::T.$ms.'Z',
            'trace'       => ['id' => 't1', 'span_id' => $id, 'parent_span_id' => $parent],
            'transaction' => ['id' => $id],
        ], $extra);
    }

    /** @param array<string, mixed> $extra */
    private function span(string $id, string $txId, string $ms, string $type = 'db.query', array $extra = []): array
    {
        return array_merge([
            'kind'        => 'span', 'type' => $type, 'name' => $id, 'duration_ms' => 5.0,
            '@timestamp'  => self::T.$ms.'Z',
            'trace'       => ['id' => 't1', 'span_id' => $id, 'parent_span_id' => $txId],
            'transaction' => ['id' => $txId],
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function activity(string $ms): array
    {
        return ['event_id' => 'a'.$ms, '@timestamp' => self::T.$ms.'Z', 'action' => 'updated', 'trace' => ['id' => 't1']];
    }

    /** @return array<string, mixed> */
    private function http(string $ms): array
    {
        return ['event_id' => 'h'.$ms, '@timestamp' => self::T.$ms.'Z', 'http' => ['method' => 'GET', 'latency_ms' => 12.5], 'trace' => ['id' => 't1']];
    }
}
