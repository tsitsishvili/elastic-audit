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

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (self $model): void {
                $model->logActivityEvent('restored', []);
            });

            static::forceDeleted(function (self $model): void {
                $model->logActivityEvent('force_deleted', []);
            });
        }
    }

    private function logActivityEvent(string $event, array $changes): void
    {
        $entityType = $this->activityEntityType();

        [$actorType, $actorId] = $this->resolveActivityActor();

        $context = new ActivityLogContext(
            actorType: $actorType,
            actorId: $actorId,
            entityType: $entityType,
            entityId: $this->activityEntityId(),
            requestId: (string) Str::ulid(),
            retentionDays: (int) config('activity_logs.retention_days', 360),
            traceParent: app()->bound('request') ? request()->headers->get('traceparent') : null,
        );

        app(ActivityLogger::class)->record(
            action: $entityType . '.' . $event,
            context: $context,
            changes: $changes,
            metadata: $this->activityMetadata($event, $changes),
        );
    }

    /**
     * Extra contextual data attached to every auto-logged activity event.
     *
     * Override in the model to enrich events (e.g. request IP, tenant id,
     * tags, arbitrary arrays). Redacted by key name like `changes` before
     * queueing; stored but not indexed in Elasticsearch.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    protected function activityMetadata(string $event, array $changes): array
    {
        return [];
    }

    protected function activityEntityType(): string
    {
        return property_exists($this, 'activityEntityType')
            ? $this->activityEntityType
            : Str::snake(class_basename($this));
    }

    protected function activityEntityId(): string
    {
        return (string) $this->getKey();
    }

    /**
     * @return array{0: string, 1: int|string|null}
     */
    protected function activityActor(): array
    {
        if (Auth::check()) {
            return ['user', Auth::id()];
        }

        return ['system', null];
    }

    private function resolveActivityActor(): array
    {
        [$actorType, $actorId] = $this->activityActor();

        if (! is_int($actorId) && ! is_string($actorId)) {
            $actorId = null;
        }

        if (is_string($actorId) && trim($actorId) === '') {
            $actorId = null;
        }

        return [(string) $actorType, $actorId];
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
