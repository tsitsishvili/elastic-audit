<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Closure;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;

/**
 * In-memory test double for the logs Elasticsearch client. Records every search
 * call and returns a configurable response so tests can drive the dashboard
 * without a live cluster.
 */
class FakeLogElasticsearchClient implements LogElasticsearchClientInterface
{
    /** @var list<array<string, mixed>> Captured params from every search() call. */
    public array $searchCalls = [];

    /** @var array<string, mixed> Default response returned from search(). */
    public array $searchResponse = [
        'hits' => ['total' => ['value' => 0], 'hits' => []],
    ];

    /** Optional resolver to return different responses per call. */
    public ?Closure $searchResolver = null;

    public function search(array $params): array
    {
        $this->searchCalls[] = $params;

        if ($this->searchResolver !== null) {
            return ($this->searchResolver)($params);
        }

        return $this->searchResponse;
    }

    public function lastSearch(): array
    {
        return $this->searchCalls[array_key_last($this->searchCalls)] ?? [];
    }

    public function index(array $params): void {}

    public function bulk(array $params): void {}

    public function deleteByQuery(array $params): array
    {
        return [];
    }

    public function createIndex(array $params): array
    {
        return [];
    }

    public function existsIndex(string $index): bool
    {
        return true;
    }

    public function putAlias(string $index, string $name, array $params = []): void {}

    public function existsAlias(string $name): bool
    {
        return true;
    }

    public function updateAliases(array $actions): void {}
}
