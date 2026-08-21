<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ProfileMapping;

final class ProfileMappingTest extends TestCase
{
    public function test_profile_mapping_keeps_raw_payload_unindexed(): void
    {
        $mapping = ProfileMapping::get();

        $this->assertSame('profiles', $mapping['_meta']['elastic_audit']['subsystem']);
        $this->assertSame(ProfileData::SCHEMA_VERSION, $mapping['_meta']['elastic_audit']['schema_version']);
        $this->assertFalse($mapping['properties']['payload']['enabled']);
        $this->assertSame('nested', $mapping['properties']['hot_frames']['type']);
    }
}
