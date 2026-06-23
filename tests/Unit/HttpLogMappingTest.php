<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;
use PHPUnit\Framework\TestCase;

class HttpLogMappingTest extends TestCase
{
    public function test_get_returns_strict_dynamic_mapping(): void
    {
        $mapping = HttpLogMapping::get();

        $this->assertSame('strict', $mapping['dynamic']);
    }

    public function test_get_contains_required_top_level_fields(): void
    {
        $props = HttpLogMapping::get()['properties'];

        foreach (['@timestamp', 'event_id', 'provider', 'event_type', 'direction', 'success', 'retention_days'] as $field) {
            $this->assertArrayHasKey($field, $props, "Missing field: {$field}");
        }
    }

    public function test_get_contains_nested_http_fields(): void
    {
        $http = HttpLogMapping::get()['properties']['http']['properties'];

        foreach (['method', 'url', 'status_code', 'latency_ms', 'timed_out'] as $field) {
            $this->assertArrayHasKey($field, $http, "Missing http.{$field}");
        }
    }

    public function test_get_contains_request_and_response_fields(): void
    {
        $props = HttpLogMapping::get()['properties'];

        $this->assertArrayHasKey('request', $props);
        $this->assertArrayHasKey('response', $props);
        $this->assertArrayHasKey('body_preview', $props['request']['properties']);
        $this->assertArrayHasKey('body_preview', $props['response']['properties']);
    }
}
