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
    private const ERROR_MESSAGE_MAX_BYTES = 2048;

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
                changes: $this->redactChanges($changes),
                metadata: (array) $this->redactor->redactBody($metadata),
                success: $success,
                errorClass: $errorClass,
                errorMessage: $this->sanitizeErrorMessage($errorMessage),
            );

            LogActivityJob::dispatch($data);
        } catch (Throwable) {
            // Never let logging failures propagate.
        }
    }

    /**
     * Preserve the conventional old/new diff shape even when the field name
     * itself causes the generic redactor to replace the complete value.
     */
    private function redactChanges(array $changes): array
    {
        $redacted = (array) $this->redactor->redactBody($changes);

        foreach ($changes as $field => $diff) {
            if (! is_array($diff)
                || ! array_key_exists('old', $diff)
                || ! array_key_exists('new', $diff)
                || ($redacted[$field] ?? null) !== '[REDACTED]') {
                continue;
            }

            $redacted[$field] = [
                'old' => '[REDACTED]',
                'new' => '[REDACTED]',
            ];
        }

        return $redacted;
    }

    /**
     * Error messages are caller-controlled diagnostic text. Sanitize common
     * credential forms and cap the queued value without splitting UTF-8.
     */
    private function sanitizeErrorMessage(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = $this->redactor->sanitizeErrorMessage($message);

        return mb_strcut($message, 0, self::ERROR_MESSAGE_MAX_BYTES, 'UTF-8');
    }
}
