<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Endpoints\Indices;
use Elastic\Elasticsearch\Response\Elasticsearch;

interface SpyElasticsearchClientInterface extends ClientInterface
{
    public function index(?array $params = null): Elasticsearch;

    public function bulk(?array $params = null): Elasticsearch;

    public function search(?array $params = null): Elasticsearch;

    public function deleteByQuery(?array $params = null): Elasticsearch;

    public function indices(): Indices;
}
