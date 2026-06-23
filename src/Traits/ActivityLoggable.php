<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ActivityLogContext;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;

trait ActivityLoggable
{
    public static function bootActivityLoggable(): void
    {
        static::created(function (self $model): void {
            $model->logActivityEvent('created', $model->activityChangesForCreate());
        });

        static::updated(function (self $model): void {
            $changes = $model->activityChangesForUpdate();

            if (! empty($changes)) {
                $model->logActivityEvent('updated', $changes);
            }
        });

        static::deleted(function (self $model): void {
            $model->logActivityEvent('deleted', []);
        });
    }

    private function logActivityEvent(string $event, array $changes): void
    {
        $entityType = property_exists($this, 'activityEntityType')
            ? $this->activityEntityType
            : Str::snake(class_basename($this));

        [$actorType, $actorId] = $this->resolveActivityActor();

        $context = new ActivityLogContext(
            actorType: $actorType,
            actorId: $actorId,
            entityType: $entityType,
            entityId: (string) $this->getKey(),
            requestId: (string) Str::ulid(),
            retentionDays: (int) config('activity_logs.retention_days', 360),
        );

        app(ActivityLogger::class)->record(
            action: $entityType . '.' . $event,
            context: $context,
            changes: $changes,
        );
    }

    private function resolveActivityActor(): array
    {
        if (Auth::check()) {
            return ['user', Auth::id()];
        }

        return ['system', null];
    }

    private function activityChangesForCreate(): array
    {
        return array_map(
            fn ($value) => ['old' => null, 'new' => $value],
            $this->filterActivityFields($this->getAttributes()),
        );
    }

    private function activityChangesForUpdate(): array
    {
        $dirty   = $this->filterActivityFields($this->getDirty());
        $changes = [];

        foreach ($dirty as $field => $newValue) {
            $changes[$field] = [
                'old' => $this->getOriginal($field),
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    private function filterActivityFields(array $attrs): array
    {
        $except = property_exists($this, 'activityLogExcept')
            ? $this->activityLogExcept
            : ['created_at', 'updated_at', 'deleted_at'];

        $only = property_exists($this, 'activityLogOnly')
            ? $this->activityLogOnly
            : [];

        if (! empty($only)) {
            $attrs = array_intersect_key($attrs, array_flip($only));
        }

        foreach ($except as $field) {
            unset($attrs[$field]);
        }

        return $attrs;
    }
}
