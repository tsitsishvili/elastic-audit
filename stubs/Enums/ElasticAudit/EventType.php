<?php

declare(strict_types=1);

namespace App\Enums\ElasticAudit;

use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;

// String-backed so values land directly in Elasticsearch keyword fields.
enum EventType: string implements EventTypeContract
{
}
