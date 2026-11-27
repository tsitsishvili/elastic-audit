<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class PruneActivityLogCommand extends PruneCommand
{
    protected $signature = 'activity-logs:prune';

    protected $description = 'Delete activity log documents older than their retention_days value.';

    protected string $configKey = 'activity_logs';
}
