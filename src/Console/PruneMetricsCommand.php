<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class PruneMetricsCommand extends PruneCommand
{
    protected $signature = 'elastic-audit:metrics:prune';

    protected $description = 'Delete metric documents older than their retention_days value.';

    protected string $configKey = 'elastic_audit_metrics';
}
