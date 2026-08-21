<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Symfony\Component\Mime\Email;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Jobs\LogMetricBatchJob;
use Tsitsishvili\ElasticAudit\Jobs\LogProfileJob;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\ExecutionContextId;
use Tsitsishvili\ElasticAudit\Support\TraceContext;
use WeakMap;

final class ApplicationMetricsSubscriber
{
    private const TIMER_TTL_NS = 300_000_000_000;

    /** @var list<string> */
    private const DEFAULT_EXCLUDED_COMMANDS = [
        'queue:work',
        'queue:listen',
        'schedule:work',
        'horizon',
        'octane:start',
        'reverb:start',
        'pulse:work',
    ];

    /** @var array<int, array{started_at: int, span_id: ?string}> */
    private array $outgoingRequests = [];

    /** @var array<int, array{token: ?string, job: string, connection: string, queue: string, attempt: int, wait_ms: ?float}> */
    private array $jobs = [];

    /** @var array<int, true> */
    private array $metricJobs = [];

    /** @var array<string, array{started_at: int, span_id: ?string, job: string, connection: string, queue: string}> */
    private array $queuePublishes = [];

    /** @var array<int, ?string> */
    private array $commands = [];

    /** @var array<int, array{token: ?string, name: string, fingerprint: string}> */
    private array $scheduledTasks = [];

    /** @var array<string, list<int>> */
    private array $cacheTimers = [];

    /** @var WeakMap<Email, int> */
    private WeakMap $mailTimers;

    /** @var array<string, int> */
    private array $notificationTimers = [];

    public function __construct(
        private readonly MetricsRecorder $metrics,
        private readonly AuditSourceResolver $sourceResolver,
        private readonly SqlStatementNormalizer $sql,
        private readonly EndpointNormalizer $endpoints,
        private readonly OutgoingTracePropagation $propagation,
    ) {
        $this->mailTimers = new WeakMap;
    }

    public function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'queryExecuted']);
        $events->listen(RequestSending::class, [$this, 'requestSending']);
        $events->listen(ResponseReceived::class, [$this, 'responseReceived']);
        $events->listen(ConnectionFailed::class, [$this, 'connectionFailed']);
        $events->listen(JobQueueing::class, [$this, 'jobQueueing']);
        $events->listen(JobQueued::class, [$this, 'jobQueued']);
        $events->listen(JobProcessing::class, [$this, 'jobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'jobProcessed']);
        $events->listen(JobExceptionOccurred::class, [$this, 'jobFailed']);
        $events->listen(JobTimedOut::class, [$this, 'jobTimedOut']);
        $events->listen(CommandStarting::class, [$this, 'commandStarting']);
        $events->listen(CommandFinished::class, [$this, 'commandFinished']);
        $events->listen(ScheduledTaskStarting::class, [$this, 'scheduledTaskStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'scheduledTaskFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'scheduledTaskFailed']);
        $events->listen(CommandExecuted::class, [$this, 'redisCommandExecuted']);
        $events->listen(CommandFailed::class, [$this, 'redisCommandFailed']);
        $events->listen(RetrievingKey::class, fn (RetrievingKey $event) => $this->cacheStarting($event, 'get'));
        $events->listen(CacheHit::class, fn (CacheHit $event) => $this->cacheFinished($event, 'get', 'hit', MetricData::OUTCOME_SUCCESS));
        $events->listen(CacheMissed::class, fn (CacheMissed $event) => $this->cacheFinished($event, 'get', 'miss', MetricData::OUTCOME_SUCCESS));
        $events->listen(WritingKey::class, fn (WritingKey $event) => $this->cacheStarting($event, 'put'));
        $events->listen(KeyWritten::class, fn (KeyWritten $event) => $this->cacheFinished($event, 'put', 'written', MetricData::OUTCOME_SUCCESS));
        $events->listen(KeyWriteFailed::class, fn (KeyWriteFailed $event) => $this->cacheFinished($event, 'put', 'failed', MetricData::OUTCOME_FAILURE));
        $events->listen(ForgettingKey::class, fn (ForgettingKey $event) => $this->cacheStarting($event, 'forget'));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $event) => $this->cacheFinished($event, 'forget', 'forgotten', MetricData::OUTCOME_SUCCESS));
        $events->listen(KeyForgetFailed::class, fn (KeyForgetFailed $event) => $this->cacheFinished($event, 'forget', 'failed', MetricData::OUTCOME_FAILURE));
        $events->listen(MessageSending::class, [$this, 'messageSending']);
        $events->listen(MessageSent::class, [$this, 'messageSent']);
        $events->listen(NotificationSending::class, [$this, 'notificationSending']);
        $events->listen(NotificationSent::class, [$this, 'notificationSent']);
        $events->listen(NotificationFailed::class, [$this, 'notificationFailed']);
    }

    public function queryExecuted(QueryExecuted $event): void
    {
        if ($this->metrics->isSuppressed() || ! $this->metrics->categoryEnabled('queries')) {
            return;
        }

        try {
            $driver = (string) $event->connection->getDriverName();
        } catch (Throwable) {
            $driver = 'unknown';
        }

        $maxBytes      = max(1, (int) config('elastic_audit_metrics.capture.queries.max_statement_bytes', 4096));
        $fullStatement = $this->sql->normalize((string) $event->sql, 1_048_576, $driver);
        $statement     = mb_strcut($fullStatement, 0, $maxBytes, 'UTF-8');
        $operation     = $this->sql->operation($fullStatement);
        $include       = (bool) config('elastic_audit_metrics.capture.queries.include_statement', true);

        $this->metrics->record(
            category: 'queries',
            type: MetricData::TYPE_DB_QUERY,
            name: $operation.' '.$event->connectionName,
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: (float) $event->time,
            source: $this->sourceResolver->resolve(),
            db: [
                'connection'  => (string) $event->connectionName,
                'driver'      => $driver,
                'operation'   => $operation,
                'statement'   => $include ? $statement : null,
                'fingerprint' => $this->sql->fingerprint($fullStatement),
            ],
        );
    }

    public function requestSending(RequestSending $event): void
    {
        $this->purgeOutgoingTimers();

        if ($this->metrics->isSuppressed()
            || ! $this->metrics->categoryEnabled('outgoing_http')
            || $this->excludedHost($event->request->url())) {
            return;
        }

        $request                                         = $event->request->toPsrRequest();
        $this->outgoingRequests[spl_object_id($request)] = [
            'started_at' => hrtime(true),
            'span_id'    => $this->propagation->spanId($request),
        ];
    }

    public function responseReceived(ResponseReceived $event): void
    {
        $status = $event->response->status();
        $this->finishOutgoing(
            $this->outgoingRequestKey($event->request),
            $event->request->method(),
            $event->request->url(),
            $status >= 400 ? MetricData::OUTCOME_FAILURE : MetricData::OUTCOME_SUCCESS,
            $status,
        );
    }

    public function connectionFailed(ConnectionFailed $event): void
    {
        $this->finishOutgoing(
            $this->outgoingRequestKey($event->request),
            $event->request->method(),
            $event->request->url(),
            MetricData::OUTCOME_FAILURE,
            null,
        );
    }

    public function jobQueueing(JobQueueing $event): void
    {
        $this->purgeQueuePublishTimers();

        if ($this->metrics->isSuppressed() || ! $this->metrics->categoryEnabled('queue_publish')) {
            return;
        }

        $payload = $this->decodedQueuePayload($event);
        $uuid    = isset($payload['uuid']) && is_string($payload['uuid']) ? $payload['uuid'] : null;

        if ($uuid === null) {
            return;
        }

        $trace = TraceContext::fromTraceParent(
            data_get($payload, 'elastic_audit_trace.traceparent'),
            data_get($payload, 'elastic_audit_trace.tracestate'),
        );

        $this->queuePublishes[$uuid] = [
            'started_at' => hrtime(true),
            'span_id'    => $trace->spanId,
            'job'        => $this->queuedJobName($event->job, $payload),
            'connection' => (string) $event->connectionName,
            'queue'      => (string) ($event->queue ?? 'default'),
        ];
    }

    public function jobQueued(JobQueued $event): void
    {
        $payload = $this->decodedQueuePayload($event);
        $uuid    = isset($payload['uuid']) && is_string($payload['uuid']) ? $payload['uuid'] : null;
        $timer   = $uuid !== null ? ($this->queuePublishes[$uuid] ?? null) : null;

        if ($uuid !== null) {
            unset($this->queuePublishes[$uuid]);
        }

        if ($timer === null) {
            return;
        }

        $this->metrics->record(
            category: 'queue_publish',
            type: MetricData::TYPE_QUEUE_PUBLISH,
            name: $timer['job'],
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: $this->elapsedMs($timer['started_at']),
            queue: [
                'connection' => $timer['connection'],
                'name'       => $timer['queue'],
                'job'        => $timer['job'],
            ],
            spanId: $timer['span_id'],
        );
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $key = spl_object_id($event->job);
        $job = $this->jobName($event->job);

        if ($job === LogMetricBatchJob::class || $job === LogProfileJob::class) {
            $this->metricJobs[$key] = true;
            $this->metrics->suppress();

            return;
        }

        if (! $this->metrics->categoryEnabled('jobs')) {
            return;
        }

        $payload   = $this->jobPayload($event->job);
        $createdAt = $payload['createdAt'] ?? null;
        $waitMs    = is_int($createdAt) || is_float($createdAt)
            ? max(0.0, (microtime(true) - (float) $createdAt) * 1000)
            : null;
        $queue   = $this->jobQueue($event->job);
        $attempt = $this->jobAttempt($event->job);
        $trace   = TraceContext::fromTraceParent(
            data_get($payload, 'elastic_audit_trace.traceparent'),
            data_get($payload, 'elastic_audit_trace.tracestate'),
        );

        $this->jobs[$key] = [
            'token' => $this->metrics->begin(
                category: 'jobs',
                type: MetricData::TYPE_QUEUE_JOB,
                name: $job,
                source: $this->sourceResolver->resolve(),
                independentRoot: true,
                upstream: $trace,
            ),
            'job'        => $job,
            'connection' => (string) $event->connectionName,
            'queue'      => $queue,
            'attempt'    => $attempt,
            'wait_ms'    => $waitMs,
        ];
    }

    public function jobProcessed(JobProcessed $event): void
    {
        $this->finishJob($event->job, MetricData::OUTCOME_SUCCESS);
    }

    public function jobFailed(JobExceptionOccurred $event): void
    {
        $this->finishJob($event->job, MetricData::OUTCOME_FAILURE);
    }

    public function jobTimedOut(JobTimedOut $event): void
    {
        $this->finishJob($event->job, MetricData::OUTCOME_FAILURE);
    }

    public function commandStarting(CommandStarting $event): void
    {
        if (! $this->metrics->categoryEnabled('commands') || $this->excludedCommand($event->command)) {
            return;
        }

        $this->commands[spl_object_id($event->input)] = $this->metrics->begin(
            category: 'commands',
            type: MetricData::TYPE_CONSOLE_COMMAND,
            name: $event->command,
            source: $this->sourceResolver->resolve(),
        );
    }

    public function commandFinished(CommandFinished $event): void
    {
        $key   = spl_object_id($event->input);
        $token = $this->commands[$key] ?? null;
        unset($this->commands[$key]);

        $this->metrics->finish(
            token: $token,
            name: $event->command,
            outcome: $event->exitCode === 0 ? MetricData::OUTCOME_SUCCESS : MetricData::OUTCOME_FAILURE,
            console: [
                'command'   => $event->command,
                'exit_code' => $event->exitCode,
            ],
        );
    }

    public function scheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        if (! $this->metrics->categoryEnabled('scheduled_tasks')) {
            return;
        }

        [$name, $fingerprint]                              = $this->scheduledTaskIdentity($event->task);
        $this->scheduledTasks[spl_object_id($event->task)] = [
            'token' => $this->metrics->begin(
                category: 'scheduled_tasks',
                type: MetricData::TYPE_SCHEDULED_TASK,
                name: $name,
                source: $this->sourceResolver->resolve(),
                independentRoot: true,
            ),
            'name'        => $name,
            'fingerprint' => $fingerprint,
        ];
    }

    public function scheduledTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->finishScheduledTask($event->task, MetricData::OUTCOME_SUCCESS);
    }

    public function scheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->finishScheduledTask($event->task, MetricData::OUTCOME_FAILURE);
    }

    public function redisCommandExecuted(CommandExecuted $event): void
    {
        $this->metrics->record(
            category: 'redis',
            type: MetricData::TYPE_REDIS_COMMAND,
            name: strtoupper((string) $event->command),
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: max(0.0, (float) $event->time),
            redis: [
                'command'    => strtoupper((string) $event->command),
                'connection' => (string) $event->connectionName,
            ],
        );
    }

    public function redisCommandFailed(CommandFailed $event): void
    {
        $this->metrics->record(
            category: 'redis',
            type: MetricData::TYPE_REDIS_COMMAND,
            name: strtoupper((string) $event->command),
            outcome: MetricData::OUTCOME_FAILURE,
            durationMs: 0,
            redis: [
                'command'    => strtoupper((string) $event->command),
                'connection' => (string) $event->connectionName,
            ],
        );
    }

    public function messageSending(MessageSending $event): void
    {
        if ($this->metrics->categoryEnabled('mail')) {
            $this->mailTimers[$event->message] = (int) hrtime(true);
        }
    }

    public function messageSent(MessageSent $event): void
    {
        $message = $event->message;

        if (! isset($this->mailTimers[$message])) {
            return;
        }

        $startedAt = $this->mailTimers[$message];
        unset($this->mailTimers[$message]);

        $this->metrics->record(
            category: 'mail',
            type: MetricData::TYPE_MAIL_SEND,
            name: 'mail.send',
            outcome: MetricData::OUTCOME_SUCCESS,
            durationMs: $this->elapsedMs($startedAt),
            mail: ['transport' => 'default'],
        );
    }

    public function notificationSending(NotificationSending $event): void
    {
        $this->purgeNotificationTimers();

        if ($this->metrics->categoryEnabled('notifications')) {
            $this->notificationTimers[$this->notificationKey($event)] = hrtime(true);
        }
    }

    public function notificationSent(NotificationSent $event): void
    {
        $this->finishNotification($event, MetricData::OUTCOME_SUCCESS);
    }

    public function notificationFailed(NotificationFailed $event): void
    {
        $this->finishNotification($event, MetricData::OUTCOME_FAILURE);
    }

    private function finishOutgoing(
        int $key,
        string $method,
        string $url,
        string $outcome,
        ?int $status,
    ): void {
        $timer = $this->outgoingRequests[$key] ?? null;
        unset($this->outgoingRequests[$key]);

        if ($timer === null) {
            return;
        }

        $includePath = (bool) config('elastic_audit_metrics.capture.outgoing_http.include_path', true);
        $endpoint    = $this->endpoints->normalize($url, $includePath);
        $method      = strtoupper($method);
        $target      = ($endpoint['host'] ?? 'unknown').($endpoint['path'] ?? '');

        $this->metrics->record(
            category: 'outgoing_http',
            type: MetricData::TYPE_HTTP_CLIENT,
            name: trim("{$method} {$target}"),
            outcome: $outcome,
            durationMs: $this->elapsedMs($timer['started_at']),
            source: $this->sourceResolver->resolve(),
            http: [
                'method'      => $method,
                'host'        => $endpoint['host'],
                'path'        => $endpoint['path'],
                'status_code' => $status,
            ],
            spanId: $timer['span_id'],
        );
    }

    private function outgoingRequestKey(ClientRequest $request): int
    {
        return spl_object_id($request->toPsrRequest());
    }

    private function excludedCommand(string $command): bool
    {
        foreach ((array) config(
            'elastic_audit_metrics.capture.commands.exclude',
            self::DEFAULT_EXCLUDED_COMMANDS,
        ) as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    private function finishJob(object $jobObject, string $outcome): void
    {
        $key = spl_object_id($jobObject);

        if (isset($this->metricJobs[$key])) {
            unset($this->metricJobs[$key]);
            $this->metrics->resume();

            return;
        }

        $job = $this->jobs[$key] ?? null;
        unset($this->jobs[$key]);

        if ($job === null) {
            return;
        }

        $this->metrics->finish(
            token: $job['token'],
            name: $job['job'],
            outcome: $outcome,
            queue: [
                'connection' => $job['connection'],
                'name'       => $job['queue'],
                'job'        => $job['job'],
                'attempt'    => $job['attempt'],
                'wait_ms'    => $job['wait_ms'],
            ],
        );
    }

    private function finishScheduledTask(object $task, string $outcome): void
    {
        $key   = spl_object_id($task);
        $timer = $this->scheduledTasks[$key] ?? null;
        unset($this->scheduledTasks[$key]);

        if ($timer === null) {
            return;
        }

        $exitCode = isset($task->exitCode) && is_int($task->exitCode) ? $task->exitCode : null;
        $this->metrics->finish(
            token: $timer['token'],
            name: $timer['name'],
            outcome: $outcome,
            scheduler: [
                'task'        => $timer['name'],
                'fingerprint' => $timer['fingerprint'],
                'exit_code'   => $exitCode,
            ],
        );
    }

    private function cacheStarting(CacheEvent $event, string $operation): void
    {
        $this->purgeCacheTimers();

        if (! $this->metrics->categoryEnabled('cache')) {
            return;
        }

        $key                       = $this->cacheTimerKey($event, $operation);
        $this->cacheTimers[$key][] = hrtime(true);
    }

    private function cacheFinished(CacheEvent $event, string $operation, string $result, string $outcome): void
    {
        $key                     = $this->cacheTimerKey($event, $operation);
        $timers                  = $this->cacheTimers[$key] ?? [];
        $started                 = array_pop($timers);
        $this->cacheTimers[$key] = $timers;

        if ($this->cacheTimers[$key] === []) {
            unset($this->cacheTimers[$key]);
        }

        if (! is_int($started)) {
            return;
        }

        $store = is_string($event->storeName) && $event->storeName !== '' ? $event->storeName : 'default';
        $this->metrics->record(
            category: 'cache',
            type: MetricData::TYPE_CACHE_OPERATION,
            name: "cache.{$operation} {$store}",
            outcome: $outcome,
            durationMs: $this->elapsedMs($started),
            cache: [
                'operation' => $operation,
                'store'     => $store,
                'result'    => $result,
            ],
        );
    }

    private function cacheTimerKey(CacheEvent $event, string $operation): string
    {
        return ExecutionContextId::current().'|'.$operation.'|'.(string) $event->storeName.'|'.hash('sha256', (string) $event->key);
    }

    private function finishNotification(NotificationSent|NotificationFailed $event, string $outcome): void
    {
        $key       = $this->notificationKey($event);
        $startedAt = $this->notificationTimers[$key] ?? null;
        unset($this->notificationTimers[$key]);

        if ($startedAt === null) {
            return;
        }

        $class = $event->notification::class;
        $this->metrics->record(
            category: 'notifications',
            type: MetricData::TYPE_NOTIFICATION_SEND,
            name: $class.' '.$event->channel,
            outcome: $outcome,
            durationMs: $this->elapsedMs($startedAt),
            notification: [
                'class'   => $class,
                'channel' => (string) $event->channel,
            ],
        );
    }

    private function notificationKey(NotificationSending|NotificationSent|NotificationFailed $event): string
    {
        $notification = spl_object_id($event->notification);
        $notifiable   = is_object($event->notifiable) ? spl_object_id($event->notifiable) : 0;

        return ExecutionContextId::current()."|{$notification}|{$notifiable}|".(string) $event->channel;
    }

    /** @return array{string, string} */
    private function scheduledTaskIdentity(object $task): array
    {
        $raw = isset($task->description) && is_string($task->description) && $task->description !== ''
            ? $task->description
            : (isset($task->command) && is_string($task->command) ? $task->command : 'scheduled.task');
        $name = (bool) config('elastic_audit_metrics.capture.scheduled_tasks.include_description', false)
            ? mb_strcut($raw, 0, 512, 'UTF-8')
            : 'scheduled.task';

        return [$name, hash('sha256', $raw)];
    }

    private function excludedHost(string $url): bool
    {
        $host = $this->endpoints->normalize($url, false)['host'];

        foreach ((array) config('elastic_audit_metrics.capture.outgoing_http.exclude_hosts', []) as $pattern) {
            if (is_string($pattern) && is_string($host) && fnmatch($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    private function jobName(object $job): string
    {
        try {
            return (string) $job->resolveName();
        } catch (Throwable) {
            return $job::class;
        }
    }

    /** @return array<string, mixed> */
    private function jobPayload(object $job): array
    {
        try {
            $payload = $job->payload();

            return $payload;
        } catch (Throwable) {
            return [];
        }
    }

    private function jobQueue(object $job): string
    {
        try {
            return (string) $job->getQueue();
        } catch (Throwable) {
            return 'default';
        }
    }

    private function jobAttempt(object $job): int
    {
        try {
            return max(1, (int) $job->attempts());
        } catch (Throwable) {
            return 1;
        }
    }

    /** @return array<string, mixed> */
    private function decodedQueuePayload(JobQueueing|JobQueued $event): array
    {
        try {
            $payload = $event->payload();

            return $payload;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $payload */
    private function queuedJobName(mixed $job, array $payload): string
    {
        if (is_object($job)) {
            return $job::class;
        }

        return is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'queue.job';
    }

    private function elapsedMs(int $startedAt): float
    {
        return max(0.0, (hrtime(true) - $startedAt) / 1_000_000);
    }

    private function purgeOutgoingTimers(): void
    {
        $cutoff = hrtime(true) - self::TIMER_TTL_NS;

        foreach ($this->outgoingRequests as $key => $timer) {
            if ($timer['started_at'] < $cutoff) {
                unset($this->outgoingRequests[$key]);
            }
        }
    }

    private function purgeQueuePublishTimers(): void
    {
        $cutoff = hrtime(true) - self::TIMER_TTL_NS;

        foreach ($this->queuePublishes as $key => $timer) {
            if ($timer['started_at'] < $cutoff) {
                unset($this->queuePublishes[$key]);
            }
        }
    }

    private function purgeNotificationTimers(): void
    {
        $cutoff = hrtime(true) - self::TIMER_TTL_NS;

        foreach ($this->notificationTimers as $key => $startedAt) {
            if ($startedAt < $cutoff) {
                unset($this->notificationTimers[$key]);
            }
        }
    }

    private function purgeCacheTimers(): void
    {
        $cutoff = hrtime(true) - self::TIMER_TTL_NS;

        foreach ($this->cacheTimers as $key => $timers) {
            $timers = array_values(array_filter(
                $timers,
                static fn (int $startedAt): bool => $startedAt >= $cutoff,
            ));

            if ($timers === []) {
                unset($this->cacheTimers[$key]);
            } else {
                $this->cacheTimers[$key] = $timers;
            }
        }
    }
}
