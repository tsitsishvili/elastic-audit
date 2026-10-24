<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Events;

final readonly class AuditOperationFailed
{
    public const SUBSYSTEM_HTTP = 'http';

    public const SUBSYSTEM_ACTIVITY = 'activity';

    public const SUBSYSTEM_METRICS = 'metrics';

    public const STAGE_CAPTURE = 'capture';

    public const STAGE_INDEXING = 'indexing';

    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function __construct(
        public string $subsystem,
        public string $stage,
        public string $exceptionClass,
        public string $message,
        public array $context = [],
    ) {}
}
