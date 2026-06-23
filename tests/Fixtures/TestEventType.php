<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Fixtures;

use Tsitsishvili\ElasticAudit\Contracts\EventTypeContract;

enum TestEventType: string implements EventTypeContract
{
    case DeliveryOrderCreate    = 'delivery_order_create';
    case DeliveryStatusCallback = 'delivery_status_callback';
    case PaymentCallback        = 'payment_callback';

    public function getValue(): string
    {
        return $this->value;
    }
}
