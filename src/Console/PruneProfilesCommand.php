<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

final class PruneProfilesCommand extends PruneCommand
{
    protected $signature = 'elastic-audit:profiles:prune';

    protected $description = 'Delete profile documents older than their retention_days value.';

    protected string $configKey = 'elastic_audit_metrics.profiles';
}
