<?php

declare(strict_types=1);

namespace App\Enums\ElasticAudit;

use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;

// String-backed so values land directly in ES keyword fields.
enum EventType: string implements EventTypeContract
{
    public function getValue(): string
    {
        return $this->value;
    }
}
