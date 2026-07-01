<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

use Elastic\Elasticsearch\ClientInterface;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogElasticsearchClient implements LogElasticsearchClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
    ) {}

    public function ping(): bool
    {
        try {
            return $this->client->ping()->asBool();
        } catch (Throwable $e) {
            $this->logError('LogES: ping failed', $e);

            return false;
        }
    }

    public function index(array $params): void
    {
        try {
            $this->client->index($params);
        } catch (Throwable $e) {
            $this->logError('LogES: index failed', $e);

            throw $e;
        }
    }

    public function bulk(array $params): void
    {
        try {
            $this->client->bulk($params);
        } catch (Throwable $e) {
            $this->logError('LogES: bulk failed', $e);

            throw $e;
        }
    }

    public function search(array $params): array
    {
        try {
            return $this->client->search($params)->asArray();
        } catch (Throwable $e) {
            $this->logError('LogES: search failed', $e);

            throw $e;
        }
    }

    public function deleteByQuery(array $params): array
    {
        try {
            return $this->client->deleteByQuery($params)->asArray();
        } catch (Throwable $e) {
            $this->logError('LogES: delete_by_query failed', $e);

            throw $e;
        }
    }

    public function createIndex(array $params): array
    {
        return $this->client->indices()->create($params)->asArray();
    }

    public function existsIndex(string $index): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $index])->asBool();
        } catch (NoNodeAvailableException $e) {
            throw $e;
        } catch (Throwable) {
            return false;
        }
    }

    public function putAlias(string $index, string $name, array $params = []): void
    {
        $this->client->indices()->putAlias(array_merge([
            'index' => $index,
            'name'  => $name,
        ], $params));
    }

    public function existsAlias(string $name): bool
    {
        try {
            return $this->client->indices()->existsAlias(['name' => $name])->asBool();
        } catch (NoNodeAvailableException $e) {
            throw $e;
        } catch (Throwable) {
            return false;
        }
    }

    public function getAlias(string $name): array
    {
        try {
            return $this->client->indices()->getAlias(['name' => $name])->asArray();
        } catch (Throwable $e) {
            $this->logError('LogES: get alias failed', $e);

            throw $e;
        }
    }

    public function updateAliases(array $actions): void
    {
        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    public function putLifecyclePolicy(string $name, array $policy): void
    {
        $this->client->ilm()->putLifecycle([
            'name' => $name,
            'body' => ['policy' => $policy],
        ]);
    }

    public function rollover(string $alias, array $conditions): array
    {
        return $this->client->indices()->rollover([
            'alias' => $alias,
            'body'  => ['conditions' => $conditions],
        ])->asArray();
    }

    private function logError(string $message, Throwable $e): void
    {
        Log::error($message, [
            'error' => $e->getMessage(),
            'code'  => $e->getCode(),
        ]);
    }
}
