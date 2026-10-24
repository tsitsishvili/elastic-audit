<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use InvalidArgumentException;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class MetricDataTest extends TestCase
{
    public function test_make_snapshots_trace_source_details_and_retention(): void
    {
        config(['elastic_audit_metrics.retention_days' => '14']);

        $data = MetricData::make(
            type: MetricData::TYPE_HTTP_SERVER,
            name: 'GET orders/{order}',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: -1.5,
            traceId: str_repeat('a', 32),
            parentSpanId: str_repeat('b', 16),
            source: new AuditSource('orders', 'testing', ExecutionOrigin::manual('metrics-test')),
            http: ['method' => 'GET', 'route' => 'orders/{order}'],
        );

        $this->assertSame(0.0, $data->durationMs);
        $this->assertSame(14, $data->retentionDays);
        $this->assertSame(str_repeat('a', 32), $data->traceId);
        $this->assertSame(str_repeat('b', 16), $data->parentSpanId);
        $this->assertSame(16, strlen($data->spanId));
        $this->assertSame('orders', $data->source?->serviceName);
        $this->assertSame('orders/{order}', $data->http['route']);
    }

    public function test_make_rejects_invalid_retention_config(): void
    {
        config(['elastic_audit_metrics.retention_days' => 0]);

        $this->expectException(InvalidArgumentException::class);

        MetricData::make(
            type: MetricData::TYPE_DB_QUERY,
            name: 'select mysql',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 1.0,
        );
    }
}
