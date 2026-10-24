<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ProfileData;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

final class ProfileIndexer
{
    public function __construct(
        private readonly LogElasticsearchClientInterface $client,
        private readonly string $writeAlias,
    ) {}

    public function index(ProfileData $data): void
    {
        $source = $data->source;

        $this->client->index([
            'index' => $this->writeAlias,
            'id'    => $data->profileId,
            'body'  => array_filter([
                '@timestamp'     => $data->timestamp,
                'profile_id'     => $data->profileId,
                'schema_version' => ProfileData::SCHEMA_VERSION,
                'service'        => [
                    'name'        => $source->serviceName,
                    'environment' => $source->serviceEnvironment,
                ],
                'execution' => [
                    'type'   => $source->execution->type,
                    'name'   => $source->execution->name,
                    'action' => $source->execution->action,
                ],
                'trace' => [
                    'id'             => $data->traceId,
                    'transaction_id' => $data->transactionId,
                ],
                'transaction' => [
                    'name' => $data->transactionName,
                    'type' => $data->transactionType,
                ],
                'duration_ms'    => $data->durationMs,
                'driver'         => $data->driver,
                'mode'           => $data->mode,
                'format'         => $data->format,
                'sample_rate_hz' => $data->sampleRateHz,
                'sample_count'   => $data->sampleCount,
                'truncated'      => $data->truncated,
                'hot_frames'     => $data->hotFrames,
                'payload'        => $data->payload,
                'retention_days' => $data->retentionDays,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
