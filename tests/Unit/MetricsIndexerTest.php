<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\MetricsIndexer;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class MetricsIndexerTest extends TestCase
{
    public function test_bulk_indexes_each_metric_as_an_individual_document(): void
    {
        $captured = null;
        $client   = $this->createMock(LogElasticsearchClientInterface::class);
        $data     = MetricData::make(
            type: MetricData::TYPE_DB_QUERY,
            name: 'select mysql',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: 12.75,
            source: new AuditSource('orders-api', 'production', new ExecutionOrigin('queue', 'ImportOrders')),
            db: [
                'connection'  => 'mysql',
                'driver'      => 'mysql',
                'operation'   => 'select',
                'statement'   => 'select * from orders where id = ?',
                'fingerprint' => 'abc',
            ],
        );

        $client->expects($this->once())->method('bulk')->with($this->callback(
            function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            },
        ));

        (new MetricsIndexer($client, 'app_metrics_write'))->bulk([$data]);

        $this->assertSame('app_metrics_write', $captured['body'][0]['index']['_index']);
        $this->assertSame($data->eventId, $captured['body'][0]['index']['_id']);
        $this->assertSame('orders-api', $captured['body'][1]['service']['name']);
        $this->assertSame($data->traceId, $captured['body'][1]['trace']['id']);
        $this->assertSame('select * from orders where id = ?', $captured['body'][1]['db']['statement']);
        $this->assertArrayNotHasKey('bindings', $captured['body'][1]['db']);
    }
}
