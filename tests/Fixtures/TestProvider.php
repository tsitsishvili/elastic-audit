<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Tsitsishvili\ElasticAudit\Contracts\ProviderContract;

enum TestProvider: string implements ProviderContract
{
    case Delivery = 'delivery';
    case Payment  = 'payment';

    public function getValue(): string
    {
        return $this->value;
    }
}
