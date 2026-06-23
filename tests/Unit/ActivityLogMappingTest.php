<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ActivityLogMapping;

class ActivityLogMappingTest extends TestCase
{
    public function test_mapping_has_strict_dynamic(): void
    {
        $mapping = ActivityLogMapping::get();
        $this->assertSame('strict', $mapping['dynamic']);
    }

    public function test_mapping_has_required_top_level_fields(): void
    {
        $props = ActivityLogMapping::get()['properties'];

        foreach (['@timestamp', 'event_id', 'schema_version', 'request_id', 'action', 'success', 'retention_days'] as $field) {
            $this->assertArrayHasKey($field, $props, "Missing field: {$field}");
        }
    }

    public function test_mapping_has_actor_with_type_and_id(): void
    {
        $actor = ActivityLogMapping::get()['properties']['actor']['properties'];
        $this->assertSame('keyword', $actor['type']['type']);
        $this->assertSame('long', $actor['id']['type']);
    }

    public function test_mapping_has_entity_with_type_and_id(): void
    {
        $entity = ActivityLogMapping::get()['properties']['entity']['properties'];
        $this->assertSame('keyword', $entity['type']['type']);
        $this->assertSame('keyword', $entity['id']['type']);
    }

    public function test_changes_and_metadata_are_stored_but_not_indexed(): void
    {
        $props = ActivityLogMapping::get()['properties'];
        $this->assertFalse($props['changes']['enabled']);
        $this->assertFalse($props['metadata']['enabled']);
    }
}
