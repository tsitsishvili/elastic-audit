<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use Tsitsishvili\ElasticAudit\Traits\ActivityLoggable;

// Minimal Eloquent model for testing
class TraitTestOrder extends Model
{
    use ActivityLoggable;

    public $timestamps = false;

    protected $table = 'orders';

    protected $fillable = ['status', 'amount', 'note'];

    protected string $activityEntityType = 'order';

    protected array $activityLogExcept = [];
}

class TraitTestSoftOrder extends Model
{
    use ActivityLoggable;
    use SoftDeletes;

    public $timestamps = false;

    protected $table = 'orders';

    protected $fillable = ['status', 'amount', 'note'];

    protected string $activityEntityType = 'order';

    protected array $activityLogExcept = [];
}

class ActivityLoggableTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['activity_logs.enabled' => true]);
        config(['activity_logs.retention_days' => 360]);

        // Eloquent models cache their boot state (and thus their event listeners)
        // statically across tests, but Testbench rebuilds the event dispatcher for
        // every test. Clearing booted models forces each model to re-register its
        // activity listeners against the current test's dispatcher.
        Model::clearBootedModels();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('orders', function ($table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->integer('amount')->default(100);
            $table->string('note')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Capsule::schema()->drop('orders');
        parent::tearDown();
    }

    public function test_created_dispatches_job_with_new_attributes(): void
    {
        Bus::fake();

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->action === 'order.created'
                && isset($job->data->changes['status'])
                && $job->data->changes['status']['old'] === null
                && $job->data->changes['status']['new'] === 'pending';
        });
    }

    public function test_updated_dispatches_job_with_diff_only(): void
    {
        $order = TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);
        Bus::fake(); // reset after create

        $order->update(['status' => 'paid']);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->action === 'order.updated'
                && array_key_exists('status', $job->data->changes)
                && $job->data->changes['status']['old'] === 'pending'
                && $job->data->changes['status']['new'] === 'paid'
                && ! array_key_exists('amount', $job->data->changes);
        });
    }

    public function test_deleted_dispatches_job_with_empty_changes(): void
    {
        $order = TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);
        Bus::fake(); // reset after create

        $order->delete();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->action === 'order.deleted'
                && $job->data->changes === [];
        });
    }

    public function test_actor_type_is_user_when_authenticated(): void
    {
        Bus::fake();

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn(7);

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->actorType === 'user'
                && $job->data->actorId === 7;
        });
    }

    public function test_authenticated_uuid_actor_id_is_preserved(): void
    {
        Bus::fake();

        Auth::shouldReceive('check')->andReturn(true);
        Auth::shouldReceive('id')->andReturn('550e8400-e29b-41d4-a716-446655440000');

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->actorType === 'user'
                && $job->data->actorId === '550e8400-e29b-41d4-a716-446655440000';
        });
    }

    public function test_actor_type_is_system_when_not_authenticated(): void
    {
        Bus::fake();

        Auth::shouldReceive('check')->andReturn(false);

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->actorType === 'system'
                && $job->data->actorId === null;
        });
    }

    public function test_automatic_activity_can_use_permanent_default_retention(): void
    {
        config(['activity_logs.retain_forever' => true]);
        Bus::fake();

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(
            LogActivityJob::class,
            fn (LogActivityJob $job): bool => $job->data->retentionDays === null,
        );
    }

    public function test_activity_log_except_excludes_fields(): void
    {
        Bus::fake();

        // Model that excludes 'note' from diffs
        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status', 'amount', 'note'];

            public $timestamps = false;

            protected string $activityEntityType = 'order';

            protected array $activityLogExcept = ['note'];
        };

        $model->fill(['status' => 'pending', 'amount' => 100, 'note' => 'secret'])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return ! array_key_exists('note', $job->data->changes);
        });
    }

    public function test_activity_log_only_restricts_to_listed_fields(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status', 'amount', 'note'];

            public $timestamps = false;

            protected string $activityEntityType = 'order';

            protected array $activityLogOnly = ['status'];
        };

        $model->fill(['status' => 'pending', 'amount' => 100, 'note' => 'text'])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return array_key_exists('status', $job->data->changes)
                && ! array_key_exists('amount', $job->data->changes)
                && ! array_key_exists('note', $job->data->changes);
        });
    }

    public function test_metadata_defaults_to_empty_when_not_overridden(): void
    {
        Bus::fake();

        TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->metadata === [];
        });
    }

    public function test_activity_metadata_override_is_attached_to_event(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status', 'amount', 'note'];

            public $timestamps = false;

            protected string $activityEntityType = 'order';

            protected function activityMetadata(string $event, array $changes): array
            {
                return [
                    'event'  => $event,
                    'tags'   => ['import', 'bulk'],
                    'nested' => ['source' => 'api', 'flags' => [1, 2, 3]],
                ];
            }
        };

        $model->fill(['status' => 'pending', 'amount' => 100])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->metadata['event'] === 'created'
                && $job->data->metadata['tags'] === ['import', 'bulk']
                && $job->data->metadata['nested']['flags'] === [1, 2, 3];
        });
    }

    public function test_activity_metadata_receives_event_and_changes(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status', 'amount', 'note'];

            public $timestamps = false;

            protected string $activityEntityType = 'order';

            protected function activityMetadata(string $event, array $changes): array
            {
                return [
                    'event'          => $event,
                    'changed_fields' => array_keys($changes),
                ];
            }
        };

        $model->fill(['status' => 'pending', 'amount' => 100])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->metadata['event'] === 'created'
                && in_array('status', $job->data->metadata['changed_fields'], true)
                && in_array('amount', $job->data->metadata['changed_fields'], true);
        });
    }

    public function test_soft_deleted_model_dispatches_restored_and_force_deleted_events(): void
    {
        $order = TraitTestSoftOrder::create(['status' => 'pending', 'amount' => 100]);
        $order->delete();
        Bus::fake();

        $order->restore();

        Bus::assertDispatched(LogActivityJob::class, fn (LogActivityJob $job) => $job->data->action === 'order.restored');

        Bus::fake();

        $order->forceDelete();

        Bus::assertDispatched(LogActivityJob::class, fn (LogActivityJob $job) => $job->data->action === 'order.force_deleted');
        Bus::assertNotDispatched(LogActivityJob::class, fn (LogActivityJob $job) => $job->data->action === 'order.deleted');
        Bus::assertDispatchedTimes(LogActivityJob::class, 1);
    }

    public function test_model_can_override_activity_actor_and_entity_id(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status', 'amount', 'note'];

            public $timestamps = false;

            protected function activityActor(): array
            {
                return ['job', 'worker-a'];
            }

            protected function activityEntityId(): string
            {
                return 'custom-'.$this->getKey();
            }
        };

        $model->fill(['status' => 'pending', 'amount' => 100])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->data->actorType === 'job'
                && $job->data->actorId === 'worker-a'
                && str_starts_with($job->data->entityId, 'custom-');
        });
    }

    public function test_capture_failure_does_not_break_model_persistence(): void
    {
        config(['activity_logs.retention_days' => 0]);
        Bus::fake();

        $order = TraitTestOrder::create(['status' => 'pending', 'amount' => 100]);

        $this->assertTrue($order->exists);
        Bus::assertNotDispatched(LogActivityJob::class);
    }

    public function test_fractional_actor_id_is_preserved_as_string_not_truncated(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status'];

            public $timestamps = false;

            protected function activityActor(): array
            {
                return ['user', '42.5'];
            }
        };

        $model->fill(['status' => 'pending'])->save();

        Bus::assertDispatched(
            LogActivityJob::class,
            fn (LogActivityJob $job): bool => $job->data->actorId === '42.5',
        );
    }

    public function test_numeric_string_actor_id_preserves_public_identifier_semantics(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status'];

            public $timestamps = false;

            protected function activityActor(): array
            {
                return ['user', '77'];
            }
        };

        $model->fill(['status' => 'pending'])->save();

        Bus::assertDispatched(
            LogActivityJob::class,
            fn (LogActivityJob $job): bool => $job->data->actorId === '77',
        );
    }

    public function test_blank_actor_id_is_normalized_to_null(): void
    {
        Bus::fake();

        $model = new class extends Model
        {
            use ActivityLoggable;

            protected $table = 'orders';

            protected $fillable = ['status'];

            public $timestamps = false;

            protected function activityActor(): array
            {
                return ['user', '   '];
            }
        };

        $model->fill(['status' => 'pending'])->save();

        Bus::assertDispatched(
            LogActivityJob::class,
            fn (LogActivityJob $job): bool => $job->data->actorId === null,
        );
    }
}
