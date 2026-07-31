<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;

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

        foreach (['@timestamp', 'event_id', 'service', 'execution', 'provider', 'event_type', 'direction', 'success', 'retention_days'] as $field) {
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

    public function test_user_id_is_mapped_as_keyword(): void
    {
        $this->assertSame('keyword', HttpLogMapping::get()['properties']['user_id']['type']);
    }

    public function test_mapping_contains_schema_metadata(): void
    {
        $this->assertSame(
            ['subsystem' => 'http_logs', 'schema_version' => 5],
            HttpLogMapping::get()['_meta']['elastic_audit'],
        );
    }

    public function test_mapping_indexes_service_and_execution_origin(): void
    {
        $properties = HttpLogMapping::get()['properties'];

        $this->assertSame('keyword', $properties['service']['properties']['name']['type']);
        $this->assertSame('keyword', $properties['service']['properties']['environment']['type']);
        $this->assertSame('keyword', $properties['execution']['properties']['type']['type']);
        $this->assertSame('keyword', $properties['execution']['properties']['name']['type']);
        $this->assertSame('keyword', $properties['execution']['properties']['action']['type']);
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
