<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Illuminate\Console\Command;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Support\ElasticsearchLifecycle;
use Throwable;

class CreateLogLifecyclePolicyCommand extends Command
{
    protected $signature = 'elastic-audit:lifecycle-policy';

    protected $description = 'Create or update the Elasticsearch ILM policy used by Elastic Audit indexes.';

    public function handle(LogElasticsearchClientInterface $client): int
    {
        if (! ElasticsearchLifecycle::enabled()) {
            $this->warn('LOG_ELASTICSEARCH_LIFECYCLE_ENABLED is false; creating the policy anyway.');
        }

        try {
            $client->putLifecyclePolicy(
                ElasticsearchLifecycle::policyName(),
                ElasticsearchLifecycle::policy(),
            );
        } catch (Throwable $e) {
            $this->error('Failed to create lifecycle policy: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Lifecycle policy created: ' . ElasticsearchLifecycle::policyName());

        return self::SUCCESS;
    }
}
