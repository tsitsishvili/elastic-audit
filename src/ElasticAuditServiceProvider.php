<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit;

use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Message\RequestInterface;
use Throwable;
use Tsitsishvili\ElasticAudit\Console\CreateActivityLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateHttpLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateLogLifecyclePolicyCommand;
use Tsitsishvili\ElasticAudit\Console\CreateMetricsIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateProfilesIndexCommand;
use Tsitsishvili\ElasticAudit\Console\ElasticAuditHealthCommand;
use Tsitsishvili\ElasticAudit\Console\PruneActivityLogCommand;
use Tsitsishvili\ElasticAudit\Console\PruneHttpLogCommand;
use Tsitsishvili\ElasticAudit\Console\PruneMetricsCommand;
use Tsitsishvili\ElasticAudit\Console\PruneProfilesCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverActivityLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverHttpLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverMetricsIndexCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverProfilesIndexCommand;
use Tsitsishvili\ElasticAudit\Contracts\FunctionProfiler;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAssets;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\MetricsDashboardQuery;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;
use Tsitsishvili\ElasticAudit\Http\Controllers\DashboardAssetController;
use Tsitsishvili\ElasticAudit\Http\HttpLogClientFactory;
use Tsitsishvili\ElasticAudit\Http\Middleware\ApplicationMetricsMiddleware;
use Tsitsishvili\ElasticAudit\Http\Middleware\AuthorizeDashboard;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\ApplicationMetricsSubscriber;
use Tsitsishvili\ElasticAudit\Services\AutomaticFunctionProfiler;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\EndpointNormalizer;
use Tsitsishvili\ElasticAudit\Services\ExcimerFunctionProfiler;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\MetricsIndexer;
use Tsitsishvili\ElasticAudit\Services\MetricsRecorder;
use Tsitsishvili\ElasticAudit\Services\OutgoingTracePropagation;
use Tsitsishvili\ElasticAudit\Services\ProfileIndexer;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Services\SqlStatementNormalizer;
use Tsitsishvili\ElasticAudit\Services\XhprofFunctionProfiler;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;
use Tsitsishvili\ElasticAudit\Support\AuditSourceResolver;
use Tsitsishvili\ElasticAudit\Support\MetricsExclusions;

class ElasticAuditServiceProvider extends ServiceProvider
{
    private static bool $queueTraceHookRegistered = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http_logs.php', 'http_logs');
        $this->mergeConfigFrom(__DIR__.'/../config/log_elasticsearch.php', 'log_elasticsearch');
        $this->mergeConfigFrom(__DIR__.'/../config/activity_logs.php', 'activity_logs');
        $this->mergeConfigFrom(__DIR__.'/../config/elastic_audit_metrics.php', 'elastic_audit_metrics');
        $this->configureIndexNames();

        $this->app->singleton(
            AuditSourceResolver::class,
            fn (Application $app): AuditSourceResolver => new AuditSourceResolver($app),
        );

        $this->app->singleton(LogElasticsearchClientInterface::class, function (Application $app) {
            $config = $app['config']['log_elasticsearch'];

            $hosts = array_map(function (array $host): string {
                $scheme  = $host['scheme'] ?? 'http';
                $address = $host['host'] ?? 'localhost';
                $port    = $host['port'] ?? 9200;

                return "{$scheme}://{$address}:{$port}";
            }, $config['hosts']);

            $builder = ClientBuilder::create()->setHosts($hosts);

            $username = $config['basicAuthentication']['username'] ?? '';
            $password = $config['basicAuthentication']['password'] ?? '';

            if ($username !== '' && $password !== '') {
                $builder->setBasicAuthentication($username, $password);
            }

            return new LogElasticsearchClient($builder->build());
        });

        $this->app->singleton(SensitiveDataRedactor::class, fn (): SensitiveDataRedactor => new SensitiveDataRedactor(
            headers: $this->redactionRules('http_logs.redaction.headers'),
            body: $this->redactionRules('http_logs.redaction.body'),
            undecodableBodyMode: $this->undecodableBodyMode(),
            captureMaxBytes: $this->captureMaxBytes(),
        ));

        $this->app->singleton(PaymentRedactor::class, fn (): PaymentRedactor => new PaymentRedactor(
            headers: $this->redactionRules('http_logs.redaction.headers'),
            body: $this->redactionRules('http_logs.redaction.body'),
            undecodableBodyMode: $this->undecodableBodyMode(),
            captureMaxBytes: $this->captureMaxBytes(),
        ));

        $this->app->singleton(AuditFailureReporter::class, fn (Application $app): AuditFailureReporter => new AuditFailureReporter(
            $app->make(SensitiveDataRedactor::class),
        ));

        $this->app->singleton(FunctionProfiler::class, function (Application $app): FunctionProfiler {
            $config = (array) $app['config']->get('elastic_audit_metrics.profiles', []);

            return new AutomaticFunctionProfiler(
                excimer: new ExcimerFunctionProfiler(
                    periodMs: (float) ($config['period_ms'] ?? 10.1),
                    maxDepth: (int) ($config['max_depth'] ?? 128),
                    maxSamples: (int) ($config['max_samples'] ?? 10000),
                    maxPayloadBytes: (int) ($config['max_payload_bytes'] ?? 2097152),
                    includePaths: (bool) ($config['include_paths'] ?? false),
                ),
                xhprof: new XhprofFunctionProfiler(
                    captureCpu: (bool) ($config['cpu'] ?? true),
                    captureMemory: (bool) ($config['memory'] ?? true),
                    maxEdges: (int) ($config['max_samples'] ?? 10000),
                    maxPayloadBytes: (int) ($config['max_payload_bytes'] ?? 2097152),
                    includePaths: (bool) ($config['include_paths'] ?? false),
                ),
                preferredDriver: (string) ($config['driver'] ?? 'auto'),
            );
        });
        $this->app->singleton(SqlStatementNormalizer::class);
        $this->app->singleton(EndpointNormalizer::class);
        $this->app->singleton(MetricsRecorder::class, fn (Application $app): MetricsRecorder => new MetricsRecorder(
            profiler: $app->make(FunctionProfiler::class),
            sourceResolver: $app->make(AuditSourceResolver::class),
        ));
        $this->app->singleton(OutgoingTracePropagation::class);
        $this->app->singleton(ApplicationMetricsSubscriber::class);

        $this->app->singleton(HttpPayloadRedactorResolver::class, fn (Application $app): HttpPayloadRedactorResolver => new HttpPayloadRedactorResolver(
            defaultRedactor: $app->make(SensitiveDataRedactor::class),
            paymentRedactor: $app->make(PaymentRedactor::class),
        ));

        $this->app->singleton(HttpLogger::class, fn (Application $app): HttpLogger => new HttpLogger(
            redactor: $app->make(SensitiveDataRedactor::class),
            redactorResolver: $app->make(HttpPayloadRedactorResolver::class),
            failureReporter: $app->make(AuditFailureReporter::class),
            sourceResolver: $app->make(AuditSourceResolver::class),
        ));

        $this->app->singleton(HttpLogClientFactory::class);
        $this->app->singleton(HttpLogManager::class);
        $this->app->singleton(DashboardAssets::class);

        $this->app->singleton(HttpLogIndexer::class, function (Application $app) {
            return new HttpLogIndexer(
                client: $app->make(LogElasticsearchClientInterface::class),
                writeAlias: $app['config']['http_logs']['index_alias_write'],
            );
        });

        $this->app->singleton(HttpLogDashboardQuery::class, function (Application $app) {
            return new HttpLogDashboardQuery(
                client: $app->make(LogElasticsearchClientInterface::class),
                readAlias: $app['config']['http_logs']['index_alias'],
            );
        });

        $this->app->singleton(ActivityLogIndexer::class, function (Application $app) {
            return new ActivityLogIndexer(
                client: $app->make(LogElasticsearchClientInterface::class),
                writeAlias: $app['config']['activity_logs']['index_alias_write'],
            );
        });

        $this->app->singleton(MetricsIndexer::class, function (Application $app) {
            return new MetricsIndexer(
                client: $app->make(LogElasticsearchClientInterface::class),
                writeAlias: $app['config']['elastic_audit_metrics']['index_alias_write'],
            );
        });

        $this->app->singleton(ProfileIndexer::class, function (Application $app) {
            return new ProfileIndexer(
                client: $app->make(LogElasticsearchClientInterface::class),
                writeAlias: $app['config']['elastic_audit_metrics']['profiles']['index_alias_write'],
            );
        });

        $this->app->singleton(MetricsDashboardQuery::class, function (Application $app) {
            return new MetricsDashboardQuery(
                client: $app->make(LogElasticsearchClientInterface::class),
                metricsAlias: $app['config']['elastic_audit_metrics']['index_alias'],
                profilesAlias: $app['config']['elastic_audit_metrics']['profiles']['index_alias'],
            );
        });

        $this->app->singleton(ActivityLogger::class, fn (Application $app): ActivityLogger => new ActivityLogger(
            redactor: new SensitiveDataRedactor(body: $this->redactionRules('activity_logs.redaction')),
            failureReporter: $app->make(AuditFailureReporter::class),
            sourceResolver: $app->make(AuditSourceResolver::class),
        ));

        $this->app->singleton(ActivityDashboardQuery::class, function (Application $app) {
            return new ActivityDashboardQuery(
                client: $app->make(LogElasticsearchClientInterface::class),
                readAlias: $app['config']['activity_logs']['index_alias'],
            );
        });
    }

    public function boot(): void
    {
        $this->registerAuditSourceListeners();
        $this->registerApplicationMetrics();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'elastic-audit');
        View::composer(
            'elastic-audit::*',
            fn ($view) => $view->with(
                'elasticAuditAssets',
                $this->app->make(DashboardAssets::class)->manifest(),
            ),
        );

        $this->registerDashboardAssetRoute();
        $this->registerDashboardRoutes();
        $this->registerActivityDashboardRoutes();
        $this->registerMetricsDashboardRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http_logs.php'             => config_path('http_logs.php'),
                __DIR__.'/../config/log_elasticsearch.php'     => config_path('log_elasticsearch.php'),
                __DIR__.'/../config/activity_logs.php'         => config_path('activity_logs.php'),
                __DIR__.'/../config/elastic_audit_metrics.php' => config_path('elastic_audit_metrics.php'),
                __DIR__.'/../stubs/Enums/ElasticAudit'         => app_path('Enums/ElasticAudit'),
            ], 'elastic-audit');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/elastic-audit'),
            ], 'elastic-audit-views');

            // Agent resources for applications that do not use Laravel Boost. Boost discovers
            // resources/boost itself; these copies give other agents the same guidance.
            $this->publishes([
                __DIR__.'/../resources/boost/skills/elastic-audit-development' => base_path('.ai/skills/elastic-audit-development'),
                __DIR__.'/../AGENTS.md'                                        => base_path('AGENTS.elastic-audit.md'),
            ], 'elastic-audit-ai');

            $dashboardAssets = [
                __DIR__.'/../public/vendor/elastic-audit' => public_path('vendor/elastic-audit'),
            ];

            $this->publishes($dashboardAssets, 'elastic-audit');
            $this->publishes($dashboardAssets, 'elastic-audit-assets');
        }

        $this->commands([
            CreateHttpLogIndexCommand::class,
            PruneHttpLogCommand::class,
            CreateActivityLogIndexCommand::class,
            PruneActivityLogCommand::class,
            CreateLogLifecyclePolicyCommand::class,
            RolloverHttpLogIndexCommand::class,
            RolloverActivityLogIndexCommand::class,
            CreateMetricsIndexCommand::class,
            PruneMetricsCommand::class,
            RolloverMetricsIndexCommand::class,
            CreateProfilesIndexCommand::class,
            PruneProfilesCommand::class,
            RolloverProfilesIndexCommand::class,
            ElasticAuditHealthCommand::class,
        ]);
    }

    /**
     * Build a RedactionRules from the 'allow'/'block' arrays under a config key.
     */
    private function redactionRules(string $configKey): RedactionRules
    {
        return new RedactionRules(
            allow: (array) config("{$configKey}.allow", []),
            block: (array) config("{$configKey}.block", []),
        );
    }

    private function undecodableBodyMode(): string
    {
        return (string) config(
            'http_logs.undecodable_body_mode',
            SensitiveDataRedactor::UNDECODABLE_MODE_METADATA,
        );
    }

    private function captureMaxBytes(): int
    {
        return (int) config(
            'http_logs.body_capture_max_bytes',
            SensitiveDataRedactor::DEFAULT_CAPTURE_MAX_BYTES,
        );
    }

    private function configureIndexNames(): void
    {
        $config = $this->app->make('config');
        $prefix = (string) $config->get('log_elasticsearch.index_prefix', 'app_logs');

        $derived = [
            'http_logs.index_alias'                            => "{$prefix}_http_logs",
            'http_logs.index_alias_write'                      => "{$prefix}_http_logs_write",
            'activity_logs.index_alias'                        => "{$prefix}_activity_logs",
            'activity_logs.index_alias_write'                  => "{$prefix}_activity_logs_write",
            'elastic_audit_metrics.index_alias'                => "{$prefix}_metrics",
            'elastic_audit_metrics.index_alias_write'          => "{$prefix}_metrics_write",
            'elastic_audit_metrics.profiles.index_alias'       => "{$prefix}_profiles",
            'elastic_audit_metrics.profiles.index_alias_write' => "{$prefix}_profiles_write",
            'log_elasticsearch.lifecycle.policy_name'          => "{$prefix}_elastic_audit_policy",
        ];

        foreach ($derived as $key => $value) {
            if ($config->get($key) === null) {
                $config->set($key, $value);
            }
        }
    }

    private function registerAuditSourceListeners(): void
    {
        $events = $this->app->make('events');

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            try {
                $name = (string) $event->job->resolveName();
            } catch (Throwable) {
                $name = $event->job::class;
            }

            $this->app->make(AuditSourceResolver::class)->enterQueueJob($name);
        });

        $leaveQueueJob = function (): void {
            $this->app->make(AuditSourceResolver::class)
                ->leaveQueueJob();
        };

        $events->listen(JobProcessed::class, $leaveQueueJob);
        $events->listen(JobExceptionOccurred::class, $leaveQueueJob);
        $events->listen(JobTimedOut::class, $leaveQueueJob);

        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->app->make(AuditSourceResolver::class)
                ->enterConsoleCommand($event->command);
        });

        $events->listen(CommandFinished::class, function (): void {
            $this->app->make(AuditSourceResolver::class)
                ->leaveConsoleCommand();
        });
    }

    private function registerApplicationMetrics(): void
    {
        if (! (bool) $this->app['config']->get('elastic_audit_metrics.enabled', false)) {
            return;
        }

        $this->app->make(ApplicationMetricsSubscriber::class)
            ->register($this->app->make(Dispatcher::class));

        Http::globalRequestMiddleware(
            fn (RequestInterface $request): RequestInterface => $this->app
                ->make(OutgoingTracePropagation::class)
                ->inject($request),
        );

        $this->registerQueueTraceHook();

        $kernel = $this->app->make(HttpKernel::class);

        $kernel->prependMiddleware(ApplicationMetricsMiddleware::class);
    }

    private function registerDashboardAssetRoute(): void
    {
        $httpDashboardEnabled     = (bool) ($this->app['config']['http_logs']['dashboard']['enabled'] ?? false);
        $activityDashboardEnabled = (bool) ($this->app['config']['activity_logs']['dashboard']['enabled'] ?? false);
        $metricsDashboardEnabled  = (bool) ($this->app['config']['elastic_audit_metrics']['dashboard']['enabled'] ?? false);

        if (! $httpDashboardEnabled && ! $activityDashboardEnabled && ! $metricsDashboardEnabled) {
            return;
        }

        Route::get('vendor/elastic-audit/{asset}', DashboardAssetController::class)
            ->where('asset', '.+')
            ->name('elastic-audit.assets');
    }

    private function registerDashboardRoutes(): void
    {
        $dashboard = $this->app['config']['http_logs']['dashboard'] ?? [];

        if (($dashboard['enabled'] ?? false) === false) {
            return;
        }

        Route::group([
            'prefix'     => $this->composeDashboardPrefix($dashboard, 'http-logs'),
            'middleware' => array_merge((array) ($dashboard['middleware'] ?? ['web']), [AuthorizeDashboard::class]),
            'as'         => 'http-logs.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });
    }

    private function registerActivityDashboardRoutes(): void
    {
        $dashboard = $this->app['config']['activity_logs']['dashboard'] ?? [];

        if (($dashboard['enabled'] ?? false) === false) {
            return;
        }

        Route::group([
            'prefix'     => $this->composeDashboardPrefix($dashboard, 'activity'),
            'middleware' => array_merge((array) ($dashboard['middleware'] ?? ['web']), [AuthorizeDashboard::class]),
            'as'         => 'activity-logs.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/activity_dashboard.php');
        });
    }

    private function registerMetricsDashboardRoutes(): void
    {
        $dashboard = $this->app['config']['elastic_audit_metrics']['dashboard'] ?? [];

        if (($dashboard['enabled'] ?? false) === false) {
            return;
        }

        Route::group([
            'prefix'     => $this->composeDashboardPrefix($dashboard, 'metrics'),
            'middleware' => array_merge((array) ($dashboard['middleware'] ?? ['web']), [AuthorizeDashboard::class]),
            'as'         => 'elastic-audit-metrics.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/metrics_dashboard.php');
        });
    }

    private function registerQueueTraceHook(): void
    {
        if (self::$queueTraceHookRegistered) {
            return;
        }

        Queue::createPayloadUsing(function (): array {
            if (! (bool) config('elastic_audit_metrics.enabled', false)
                || ! (bool) config('elastic_audit_metrics.trace.propagate_queue', true)) {
                return [];
            }

            $context = app(MetricsRecorder::class)->propagationContext();

            if ($context === null) {
                return [];
            }

            $spanId      = MetricData::randomId(8);
            $traceParent = $context->toTraceParent($spanId, $context->sampled);

            if ($traceParent === null) {
                return [];
            }

            return ['elastic_audit_trace' => array_filter([
                'traceparent' => $traceParent,
                'tracestate'  => $context->traceState,
            ], static fn (mixed $value): bool => $value !== null)];
        });

        self::$queueTraceHookRegistered = true;
    }

    /**
     * Build the route group prefix from an optional shared group segment and the
     * dashboard's own subpath. Shared with the metrics exclusion rules so the
     * dashboards keep excluding themselves whatever prefix they are mounted on.
     */
    private function composeDashboardPrefix(array $dashboard, string $defaultPath): string
    {
        return MetricsExclusions::dashboardPrefix($dashboard, $defaultPath);
    }
}
