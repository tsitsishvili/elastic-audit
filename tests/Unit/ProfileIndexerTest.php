<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\ProfileIndexer;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

final class ProfileIndexerTest extends TestCase
{
    public function test_indexes_profile_into_separate_write_alias(): void
    {
        $captured = null;
        $client   = $this->createMock(LogElasticsearchClientInterface::class);
        $client->expects($this->once())->method('index')->willReturnCallback(
            function (array $params) use (&$captured): void {
                $captured = $params;
            },
        );
        $profile = new ProfileData(
            profileId: 'profile-1',
            timestamp: '2026-08-12T10:00:00Z',
            traceId: str_repeat('a', 32),
            transactionId: str_repeat('b', 16),
            transactionName: 'GET orders.index',
            transactionType: 'http.server',
            durationMs: 25,
            driver: 'excimer',
            mode: 'sampling',
            format: 'speedscope',
            sampleRateHz: 99,
            sampleCount: 2,
            truncated: false,
            payload: ['profiles' => []],
            hotFrames: [],
            retentionDays: 7,
            source: new AuditSource('orders', 'production', ExecutionOrigin::manual('test')),
        );

        (new ProfileIndexer($client, 'app_profiles_write'))->index($profile);

        $this->assertSame('app_profiles_write', $captured['index']);
        $this->assertSame('profile-1', $captured['id']);
        $this->assertSame($profile->traceId, $captured['body']['trace']['id']);
        $this->assertSame(['profiles' => []], $captured['body']['payload']);
    }
}
