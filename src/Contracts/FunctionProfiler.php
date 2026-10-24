<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Contracts;

use Tsitsishvili\ElasticAudit\DataTransferObjects\CapturedProfile;

interface FunctionProfiler
{
    public function available(): bool;

    public function driver(): ?string;

    public function start(): bool;

    public function stop(): ?CapturedProfile;
}
