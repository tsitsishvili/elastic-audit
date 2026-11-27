<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

class RolloverHttpLogIndexCommand extends RolloverIndexCommand
{
    protected $signature = 'http-logs:rollover';

    protected $description = 'Roll over the HTTP logs Elasticsearch write alias when configured lifecycle conditions match.';

    protected string $configKey = 'http_logs';
}
