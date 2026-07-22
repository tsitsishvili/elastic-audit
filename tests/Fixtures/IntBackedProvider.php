<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;

enum IntBackedProvider: int implements ProviderContract
{
    case Delivery = 1;
}
