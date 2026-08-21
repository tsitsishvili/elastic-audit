<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Tsitsishvili\ElasticAudit\Dashboard\MetricsDashboardQuery;
use Tsitsishvili\ElasticAudit\Tests\Fixtures\FakeLogElasticsearchClient;

final class MetricsDashboardQueryTest extends TestCase
{
    private FakeLogElasticsearchClient $client;

    private MetricsDashboardQuery $query;

    protected function setUp(): void
    {
        $this->client = new FakeLogElasticsearchClient;
        $this->query  = new MetricsDashboardQuery($this->client, 'app_metrics', 'app_profiles');
    }

    public function test_transactions_query_only_root_documents_and_applies_filters(): void
    {
        $this->query->transactions(['type' => 'http.server', 'outcome' => 'failure'], 1, 25);
        $call    = $this->client->lastSearch();
        $filters = $call['body']['query']['bool']['filter'];

        $this->assertSame('app_metrics', $call['index']);
        $this->assertContains(['term' => ['kind' => 'transaction']], $filters);
        $this->assertContains(['term' => ['type' => 'http.server']], $filters);
        $this->assertContains(['term' => ['outcome' => 'failure']], $filters);
    }

    public function test_profile_query_uses_separate_profiles_alias(): void
    {
        $this->query->profile('profile-1');

        $this->assertSame('app_profiles', $this->client->lastSearch()['index']);
        $this->assertSame(
            ['term' => ['profile_id' => 'profile-1']],
            $this->client->lastSearch()['body']['query'],
        );
    }
}
