<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Support\TraceContext;

final class TraceContextTest extends TestCase
{
    public function test_parses_and_serializes_valid_w3c_context(): void
    {
        $header  = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $context = TraceContext::fromTraceParent($header, 'vendor=value');

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $context->traceId);
        $this->assertSame('00f067aa0ba902b7', $context->spanId);
        $this->assertTrue($context->sampled);
        $this->assertSame('vendor=value', $context->traceState);
        $this->assertSame($header, $context->toTraceParent());
    }

    public function test_rejects_zero_ids_and_version_zero_extra_fields(): void
    {
        $zero  = TraceContext::fromTraceParent('00-00000000000000000000000000000000-0000000000000000-01');
        $extra = TraceContext::fromTraceParent(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra',
        );

        $this->assertNull($zero->traceId);
        $this->assertNull($extra->traceId);
    }

    public function test_merge_keeps_explicit_non_w3c_ids_for_audit_correlation(): void
    {
        $context = TraceContext::merge(traceId: ' 550E8400-E29B-41D4-A716-446655440000 ', spanId: 'req-42');

        // Stored as 4.x stored them; only propagation requires W3C ids.
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $context->traceId);
        $this->assertSame('req-42', $context->spanId);
        $this->assertNull($context->toTraceParent());
    }

    public function test_drops_invalid_tracestate_instead_of_forwarding_it(): void
    {
        $context = TraceContext::fromTraceParent(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            "vendor=ok\r\ninjected=value",
        );

        $this->assertNull($context->traceState);
    }
}
