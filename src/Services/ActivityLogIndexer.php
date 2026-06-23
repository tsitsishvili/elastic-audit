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
        $id = hash('sha256', $data->eventId);

        $this->client->index([
            'index' => $this->writeAlias,
            'id'    => $id,
            'body'  => $this->toDocument($data),
        ]);
    }

    private function toDocument(ActivityLogData $d): array
    {
        return [
            '@timestamp'     => $d->timestamp,
            'event_id'       => $d->eventId,
            'schema_version' => ActivityLogData::SCHEMA_VERSION,
            'request_id'     => $d->requestId,
            'actor'          => [
                'type' => $d->actorType,
                'id'   => $d->actorId,
            ],
            'action'         => $d->action,
            'entity'         => [
                'type' => $d->entityType,
                'id'   => $d->entityId,
            ],
            'changes'        => $d->changes,
            'metadata'       => $d->metadata,
            'success'        => $d->success,
            'error'          => [
                'class'   => $d->errorClass,
                'message' => $d->errorMessage,
            ],
            'retention_days' => $d->retentionDays,
        ];
    }
}
