<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

interface LogElasticsearchClientInterface
{
    public function ping(): bool;

    public function index(array $params): void;

    public function bulk(array $params): void;

    public function search(array $params): array;

    public function deleteByQuery(array $params): array;

    public function createIndex(array $params): array;

    public function existsIndex(string $index): bool;

    public function putAlias(string $index, string $name, array $params = []): void;

    public function existsAlias(string $name): bool;

    public function getAlias(string $name): array;

    public function updateAliases(array $actions): void;

    public function putLifecyclePolicy(string $name, array $policy): void;

    public function rollover(string $alias, array $conditions, ?string $newIndex = null): array;
}
