<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

class ActivityLogIndexer
{
    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $writeAlias,
    ) {}

    public function index(ActivityLogData $data): void
    {
        $this->client->index([
            'index' => $this->writeAlias,
            'id'    => $data->eventId,
            'body'  => $this->toDocument($data),
        ]);
    }

    /**
     * @param  iterable<ActivityLogData>  $items
     */
    public function bulk(iterable $items): void
    {
        $body = [];

        foreach ($items as $data) {
            $body[] = ['index' => ['_index' => $this->writeAlias, '_id' => $data->eventId]];
            $body[] = $this->toDocument($data);
        }

        if ($body === []) {
            return;
        }

        $this->client->bulk(['body' => $body]);
    }

    private function toDocument(ActivityLogData $d): array
    {
        return [
            '@timestamp'     => $d->timestamp,
            'event_id'       => $d->eventId,
            'schema_version' => ActivityLogData::SCHEMA_VERSION,
            'request_id'     => $d->requestId,
            'trace'          => [
                // isset() guards serialized jobs queued before trace fields existed.
                'id'          => isset($d->traceId) ? $d->traceId : null,
                'span_id'     => isset($d->spanId) ? $d->spanId : null,
                'traceparent' => isset($d->traceParent) ? $d->traceParent : null,
            ],
            'actor' => [
                'type' => $d->actorType,
                'id'   => $d->actorId !== null ? (string) $d->actorId : null,
            ],
            'action' => $d->action,
            'entity' => [
                'type' => $d->entityType,
                'id'   => $d->entityId,
            ],
            'changes'  => $d->changes,
            'metadata' => $d->metadata,
            'success'  => $d->success,
            'error'    => [
                'class'   => $d->errorClass,
                'message' => $d->errorMessage,
            ],
            'retention_days' => $d->retentionDays,
        ];
    }
}
