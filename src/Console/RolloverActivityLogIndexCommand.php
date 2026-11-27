<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class RolloverActivityLogIndexCommand extends RolloverIndexCommand
{
    protected $signature = 'activity-logs:rollover';

    protected $description = 'Roll over the activity logs Elasticsearch write alias when configured lifecycle conditions match.';

    protected string $configKey = 'activity_logs';
}
