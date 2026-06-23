<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Tsitsishvili\ElasticAudit\Contracts\EntityTypeContract;

enum TestEntityType: string implements EntityTypeContract
{
    case Order = 'order';
    case None  = 'none';

    public function getValue(): string
    {
        return $this->value;
    }
}
