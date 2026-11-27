<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\HttpLogMapping;

class CreateHttpLogIndexCommand extends CreateIndexCommand
{
    protected $signature = 'http-logs:create-index';

    protected $description = 'Create the third-party HTTP logs Elasticsearch index and aliases.';

    protected string $configKey = 'http_logs';

    protected string $subject = 'HTTP logs';

    protected function mappings(): array
    {
        return HttpLogMapping::get();
    }
}
