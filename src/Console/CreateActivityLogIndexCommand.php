<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ActivityLogMapping;

class CreateActivityLogIndexCommand extends CreateIndexCommand
{
    protected $signature = 'activity-logs:create-index';

    protected $description = 'Create the activity logs Elasticsearch index and aliases.';

    protected string $configKey = 'activity_logs';

    protected string $subject = 'activity logs';

    protected function mappings(): array
    {
        return ActivityLogMapping::get();
    }
}
