<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services\Elasticsearch;

interface LogElasticsearchSchemaInspectorInterface
{
    public function getMapping(string $index): array;

    public function getIndexTemplate(string $name): array;
}
