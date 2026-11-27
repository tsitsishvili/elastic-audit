<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class PruneHttpLogCommand extends PruneCommand
{
    protected $signature = 'http-logs:prune';

    protected $description = 'Delete third-party HTTP log documents older than their retention_days value.';

    protected string $configKey = 'http_logs';
}
