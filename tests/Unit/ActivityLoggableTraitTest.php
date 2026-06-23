<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Tsitsishvili\ElasticAudit\Jobs\LogActivityJob;
use Tsitsishvili\ElasticAudit\Tests\TestCase;
use Tsitsishvili\ElasticAudit\Traits\ActivityLoggable;

// Minimal Eloquent model for testing
class TraitTestOrder extends Model
{
    use ActivityLoggable;

    protected $table    = 'orders';
    protected $fillable = ['status', 'amount', 'note'];
    public $timestamps  = false;

    protected string $activityEntityType = 'order';
    protected array $activityLogExcept   = [];
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

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('orders', function ($table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->integer('amount')->default(100);
            $table->string('note')->nullable();
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

    public function test_activity_log_except_excludes_fields(): void
    {
        Bus::fake();

        // Model that excludes 'note' from diffs
        $model = new class extends Model {
            use ActivityLoggable;
            protected $table    = 'orders';
            protected $fillable = ['status', 'amount', 'note'];
            public $timestamps  = false;
            protected string $activityEntityType = 'order';
            protected array $activityLogExcept   = ['note'];
        };

        $model->fill(['status' => 'pending', 'amount' => 100, 'note' => 'secret'])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return ! array_key_exists('note', $job->data->changes);
        });
    }

    public function test_activity_log_only_restricts_to_listed_fields(): void
    {
        Bus::fake();

        $model = new class extends Model {
            use ActivityLoggable;
            protected $table    = 'orders';
            protected $fillable = ['status', 'amount', 'note'];
            public $timestamps  = false;
            protected string $activityEntityType = 'order';
            protected array $activityLogOnly     = ['status'];
        };

        $model->fill(['status' => 'pending', 'amount' => 100, 'note' => 'text'])->save();

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return array_key_exists('status', $job->data->changes)
                && ! array_key_exists('amount', $job->data->changes)
                && ! array_key_exists('note', $job->data->changes);
        });
    }
}
