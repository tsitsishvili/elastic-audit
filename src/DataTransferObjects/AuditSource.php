<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final readonly class AuditSource
{
    public function __construct(
        public string $serviceName,
        public ?string $serviceEnvironment,
        public ExecutionOrigin $execution,
    ) {}
}
