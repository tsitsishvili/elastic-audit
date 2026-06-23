<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Contracts;

interface EntityTypeContract
{
    public function getValue(): string;
}
