<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ApplicationMetricContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class ProfileDataTest extends TestCase
{
    public function test_capture_becomes_trace_linked_profile_with_independent_retention(): void
    {
        config(['elastic_audit_metrics.profiles.retention_days' => 5]);
        $context = new ApplicationMetricContext(
            token: 'token',
            category: 'http',
            type: MetricData::TYPE_HTTP_SERVER,
            name: 'GET orders.index',
            traceId: str_repeat('a', 32),
            spanId: str_repeat('b', 16),
            parentSpanId: null,
            transactionId: str_repeat('b', 16),
            startedAt: hrtime(true),
            startedTimestamp: '2026-08-12T10:00:00Z',
            sampled: true,
            traceState: null,
            source: new AuditSource('orders', 'testing', ExecutionOrigin::manual('test')),
        );
        $capture = new CapturedProfile(
            driver: 'excimer',
            mode: 'sampling',
            format: 'speedscope',
            sampleRateHz: 99.0,
            sampleCount: 3,
            payload: ['profiles' => []],
            hotFrames: [],
        );

        $profile = ProfileData::fromCapture($context, $capture, 12.5);

        $this->assertSame($context->traceId, $profile->traceId);
        $this->assertSame($context->transactionId, $profile->transactionId);
        $this->assertSame('excimer', $profile->driver);
        $this->assertSame(5, $profile->retentionDays);
    }
}
