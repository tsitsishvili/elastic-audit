<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Contracts;

interface EventTypeContract
{
    public function getValue(): string;
}
