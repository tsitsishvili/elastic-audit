<?php

declare(strict_types=1);

namespace App\Enums\ElasticAudit;

use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;

// String-backed so values land directly in Elasticsearch keyword fields.
// Adding a new case requires bumping HttpLogData::SCHEMA_VERSION.
enum Provider: string implements ProviderContract
{
}
