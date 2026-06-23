<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Enums;

enum HttpDirection: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
}
