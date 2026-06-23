<?php

declare(strict_types=1);

namespace App\Enums\ElasticAudit;

use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;

// String-backed so values land directly in ES keyword fields.
enum EntityType: string implements EntityTypeContract
{
    case None = 'none';

    public function getValue(): string
    {
        return $this->value;
    }
}
