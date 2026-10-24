<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

class MetricsIndexer
{
    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $writeAlias,
    ) {}

    /** @param iterable<MetricData> $items */
    public function bulk(iterable $items): void
    {
        $body = [];

        foreach ($items as $data) {
            $body[] = ['index' => ['_index' => $this->writeAlias, '_id' => $data->eventId]];
            $body[] = $this->toDocument($data);
        }

        if ($body !== []) {
            $this->client->bulk(['body' => $body]);
        }
    }

    private function toDocument(MetricData $data): array
    {
        $source = $data->source;

        return array_filter([
            '@timestamp'     => $data->timestamp,
            'event_id'       => $data->eventId,
            'schema_version' => MetricData::SCHEMA_VERSION,
            'service'        => [
                'name'        => $source?->serviceName,
                'environment' => $source?->serviceEnvironment,
            ],
            'execution' => [
                'type'   => $source?->execution->type,
                'name'   => $source?->execution->name,
                'action' => $source?->execution->action,
            ],
            'trace' => [
                'id'             => $data->traceId,
                'span_id'        => $data->spanId,
                'parent_span_id' => $data->parentSpanId,
            ],
            'transaction' => [
                'id'         => $data->transactionId,
                'sampled'    => $data->sampled,
                'span_count' => $data->kind === MetricData::KIND_TRANSACTION ? $data->spanCount : null,
                'profile_id' => $data->profileId,
            ],
            'kind'           => $data->kind,
            'type'           => $data->type,
            'name'           => $data->name,
            'outcome'        => $data->outcome,
            'duration_ms'    => $data->durationMs,
            'retention_days' => $data->retentionDays,
            'http'           => $data->http,
            'db'             => $data->db,
            'queue'          => $data->queue,
            'console'        => $data->console,
            'redis'          => $data->redis,
            'cache'          => $data->cache,
            'mail'           => $data->mail,
            'notification'   => $data->notification,
            'scheduler'      => $data->scheduler,
            'code'           => $data->code,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
