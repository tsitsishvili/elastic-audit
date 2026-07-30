<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\DataTransferObjects;

final readonly class ExecutionOrigin
{
    public const TYPE_HTTP = 'http';

    public const TYPE_QUEUE = 'queue';

    public const TYPE_CONSOLE = 'console';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_UNKNOWN = 'unknown';

    public function __construct(
        public string $type,
        public ?string $name = null,
        public ?string $action = null,
    ) {}

    public static function manual(string $name, ?string $action = null): self
    {
        return new self(self::TYPE_MANUAL, $name, $action);
    }

    public static function unknown(): self
    {
        return new self(self::TYPE_UNKNOWN);
    }
}
