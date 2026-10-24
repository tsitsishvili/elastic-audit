<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Feature;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\MetricMapping;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Tests\TestCase;

class CreateMetricsIndexCommandTest extends TestCase
{
    public function test_creates_index_template_and_aliases(): void
    {
        $fake = new class extends FakeLogElasticsearchClient
        {
            public array $createdIndexes = [];

            public array $createdAliases = [];

            public array $indexTemplates = [];

            public function existsIndex(string $index): bool
            {
                return false;
            }

            public function existsAlias(string $name): bool
            {
                return false;
            }

            public function putIndexTemplate(string $name, array $template): void
            {
                $this->indexTemplates[$name] = $template;
            }

            public function createIndex(array $params): array
            {
                $this->createdIndexes[] = $params;

                return [];
            }

            public function putAlias(string $index, string $name, array $params = []): void
            {
                $this->createdAliases[$name] = $params;
            }
        };

        $this->app->instance(LogElasticsearchClientInterface::class, $fake);
        $this->artisan('elastic-audit:metrics:create-index')->assertSuccessful();

        $readAlias  = (string) config('elastic_audit_metrics.index_alias');
        $writeAlias = (string) config('elastic_audit_metrics.index_alias_write');
        $template   = $fake->indexTemplates["{$readAlias}_template"];

        $this->assertSame("{$readAlias}-000001", $fake->createdIndexes[0]['index']);
        $this->assertSame(MetricMapping::get(), $template['template']['mappings']);
        $this->assertArrayHasKey($readAlias, $fake->createdAliases);
        $this->assertSame(['body' => ['is_write_index' => true]], $fake->createdAliases[$writeAlias]);
    }
}
