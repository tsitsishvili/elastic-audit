<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class RolloverMetricsIndexCommand extends RolloverIndexCommand
{
    protected $signature = 'elastic-audit:metrics:rollover';

    protected $description = 'Roll over the metrics Elasticsearch write alias when configured lifecycle conditions match.';

    protected string $configKey = 'elastic_audit_metrics';
}
