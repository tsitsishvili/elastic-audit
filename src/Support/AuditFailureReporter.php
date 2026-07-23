<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use BackedEnum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Events\AuditOperationFailed;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;

final class AuditFailureReporter
{
    private const ERROR_MESSAGE_MAX_BYTES = 2048;

    private const CONTEXT_VALUE_MAX_BYTES = 512;

    private const RESERVED_CONTEXT_KEYS = [
        'subsystem',
        'stage',
        'error_class',
        'error',
    ];

    public function __construct(
        private readonly SensitiveDataRedactor $redactor = new SensitiveDataRedactor,
    ) {}

    /**
     * Report an audit failure without allowing the reporter, logger, or an event
     * listener to affect the consuming application's request or model lifecycle.
     *
     * @param  array<string, mixed>  $context
     */
    public function report(
        string $subsystem,
        string $stage,
        Throwable $exception,
        array $context = [],
        string $logMessage = 'Elastic Audit operation failed',
    ): void {
        $message = mb_strcut(
            $this->redactor->sanitizeErrorMessage($exception->getMessage()),
            0,
            self::ERROR_MESSAGE_MAX_BYTES,
            'UTF-8',
        );

        $safeContext = $this->sanitizeContext($context);
        $event       = new AuditOperationFailed(
            subsystem: $subsystem,
            stage: $stage,
            exceptionClass: $exception::class,
            message: $message,
            context: $safeContext,
        );

        try {
            Log::error($logMessage, [
                ...$safeContext,
                'subsystem'   => $subsystem,
                'stage'       => $stage,
                'error_class' => $exception::class,
                'error'       => $message,
            ]);
        } catch (Throwable) {
            // Failure reporting must never become a second application failure.
        }

        try {
            Event::dispatch($event);
        } catch (Throwable) {
            // A consumer listener must not alter the audited application flow.
        }
    }

    /**
     * Resolve the reporter lazily for failure callbacks and model traits where
     * constructor injection is unavailable.
     *
     * @param  array<string, mixed>  $context
     */
    public static function reportUsingContainer(
        string $subsystem,
        string $stage,
        Throwable $exception,
        array $context = [],
        string $logMessage = 'Elastic Audit operation failed',
    ): void {
        try {
            app(self::class)->report($subsystem, $stage, $exception, $context, $logMessage);
        } catch (Throwable) {
            // Container resolution must not make an audit failure observable to callers.
        }
    }

    /**
     * Keep failure context deliberately shallow and scalar. Payloads, headers,
     * model changes, and arbitrary metadata must never reach the fallback log.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, bool|float|int|string|null>
     */
    private function sanitizeContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (in_array($key, self::RESERVED_CONTEXT_KEYS, true)
                || preg_match('/^[a-z0-9_.-]+$/i', $key) !== 1) {
                continue;
            }

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            }

            if ($value !== null
                && ! is_bool($value)
                && ! is_float($value)
                && ! is_int($value)
                && ! is_string($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = mb_strcut(
                    $this->redactor->sanitizeErrorMessage($value),
                    0,
                    self::CONTEXT_VALUE_MAX_BYTES,
                    'UTF-8',
                );
            }

            $safe[$key] = $value;
        }

        return $safe;
    }
}
