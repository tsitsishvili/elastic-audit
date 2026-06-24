<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogData;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Throwable;

class ActivityLogger
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor = new SensitiveDataRedactor(),
    ) {
    }

    public function record(
        string $action,
        ActivityLogContext $context,
        array $changes = [],
        array $metadata = [],
        bool $success = true,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): void {
        if (! config('activity_logs.enabled', true)) {
            return;
        }

        try {
            $data = ActivityLogData::make(
                action: $action,
                context: $context,
                changes: (array) $this->redactor->redactBody($changes),
                metadata: (array) $this->redactor->redactBody($metadata),
                success: $success,
                errorClass: $errorClass,
                errorMessage: $errorMessage,
            );

            LogActivityJob::dispatch($data);
        } catch (Throwable) {
            // Never let logging failures propagate.
        }
    }
}
