<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\MetricMapping;

class MetricMappingTest extends TestCase
{
    public function test_mapping_is_strict_and_contains_apm_fields(): void
    {
        $mapping = MetricMapping::get();

        $this->assertSame('strict', $mapping['dynamic']);
        $this->assertSame(MetricData::SCHEMA_VERSION, $mapping['_meta']['elastic_audit']['schema_version']);
        $this->assertSame('keyword', $mapping['properties']['trace']['properties']['id']['type']);
        $this->assertSame('keyword', $mapping['properties']['kind']['type']);
        $this->assertSame('keyword', $mapping['properties']['transaction']['properties']['profile_id']['type']);
        $this->assertSame('double', $mapping['properties']['duration_ms']['type']);
        $this->assertSame('wildcard', $mapping['properties']['db']['properties']['statement']['type']);
        $this->assertSame('double', $mapping['properties']['queue']['properties']['wait_ms']['type']);
        $this->assertSame('keyword', $mapping['properties']['redis']['properties']['command']['type']);
        $this->assertArrayNotHasKey('payload', $mapping['properties']);
        $this->assertArrayNotHasKey('bindings', $mapping['properties']['db']['properties']);
    }
}
