<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\MetricMapping;

class CreateMetricsIndexCommand extends CreateIndexCommand
{
    protected $signature = 'elastic-audit:metrics:create-index';

    protected $description = 'Create the Elastic Audit metrics Elasticsearch index and aliases.';

    protected string $configKey = 'elastic_audit_metrics';

    protected string $subject = 'metrics';

    protected function mappings(): array
    {
        return MetricMapping::get();
    }
}
