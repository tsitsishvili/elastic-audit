<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Contracts;

interface ProviderContract
{
    public function getValue(): string;
}
