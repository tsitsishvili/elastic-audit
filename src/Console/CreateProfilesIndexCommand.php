<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Console;

use Tsitsishvili\ElasticAudit\Services\Elasticsearch\ProfileMapping;

final class CreateProfilesIndexCommand extends CreateIndexCommand
{
    protected $signature = 'elastic-audit:profiles:create-index';

    protected $description = 'Create the Elastic Audit profiles Elasticsearch index and aliases.';

    protected string $configKey = 'elastic_audit_metrics.profiles';

    protected string $subject = 'profiles';

    protected function mappings(): array
    {
        return ProfileMapping::get();
    }
}
