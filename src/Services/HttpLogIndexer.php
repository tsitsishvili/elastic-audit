<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\DataTransferObjects\HttpLogData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

class HttpLogIndexer
{
    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $writeAlias,
    ) {}

    public function index(HttpLogData $data, int $attempt = 1): void
    {
        // The optional argument is retained for existing callers. HTTP-attempt
        // state belongs to the immutable DTO, so queue retries cannot overwrite it.
        $this->client->index([
            'index' => $this->writeAlias,
            'id'    => $data->eventId,
            'body'  => $this->toDocument($data),
        ]);
    }

    /**
     * @param  iterable<HttpLogData>  $items
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

    private function toDocument(HttpLogData $d): array
    {
        return [
            '@timestamp'     => $d->timestamp,
            'event_id'       => $d->eventId,
            'schema_version' => HttpLogData::SCHEMA_VERSION,
            'request_id'     => $d->requestId,
            'provider'       => (string) $d->provider->value,
            'event_type'     => (string) $d->eventType->value,
            'direction'      => $d->direction->value,
            'user_id'        => $d->userId !== null ? (string) $d->userId : null,
            'attempt'        => $d->attempt,
            'success'        => $d->success,
            'retention_days' => $d->retentionDays,
            'trace'          => [
                // isset() guards serialized jobs queued before trace fields existed.
                'id'          => isset($d->traceId) ? $d->traceId : null,
                'span_id'     => isset($d->spanId) ? $d->spanId : null,
                'traceparent' => isset($d->traceParent) ? $d->traceParent : null,
            ],
            'http' => [
                'method'       => $d->httpMethod,
                'url'          => $d->httpUrl,
                'host'         => $d->httpHost,
                'path'         => $d->httpPath,
                'status_code'  => $d->httpStatusCode,
                'status_class' => $d->httpStatusClass,
                'latency_ms'   => $d->latencyMs,
                'timed_out'    => $d->timedOut,
            ],
            'entity' => [
                'type' => $d->entityType,
                'id'   => $d->entityId,
            ],
            'external' => [
                'id' => $d->externalId,
            ],
            'request' => [
                'headers'        => $d->request->headers,
                'body'           => $d->request->body,
                'body_preview'   => $d->request->bodyPreview,
                'body_hash'      => $d->request->bodyHash,
                'body_truncated' => $d->request->bodyTruncated,
            ],
            'response' => [
                'headers'        => $d->response->headers,
                'body'           => $d->response->body,
                'body_preview'   => $d->response->bodyPreview,
                'body_hash'      => $d->response->bodyHash,
                'body_truncated' => $d->response->bodyTruncated,
            ],
            'error' => [
                'class'   => $d->errorClass,
                'message' => $d->errorMessage,
            ],
        ];
    }
}
