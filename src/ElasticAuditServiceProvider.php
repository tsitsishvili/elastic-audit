<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit;

use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\ElasticAudit\Console\CreateActivityLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateHttpLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateLogLifecyclePolicyCommand;
use Tsitsishvili\ElasticAudit\Console\ElasticAuditHealthCommand;
use Tsitsishvili\ElasticAudit\Console\PruneActivityLogCommand;
use Tsitsishvili\ElasticAudit\Console\PruneHttpLogCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverActivityLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\RolloverHttpLogIndexCommand;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAssets;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;
use Tsitsishvili\ElasticAudit\Http\Controllers\DashboardAssetController;
use Tsitsishvili\ElasticAudit\Http\HttpLogClientFactory;
use Tsitsishvili\ElasticAudit\Http\Middleware\AuthorizeDashboard;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogger;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Redactors\HttpPayloadRedactorResolver;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\Support\AuditFailureReporter;

class ElasticAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/http_logs.php', 'http_logs');
        $this->mergeConfigFrom(__DIR__.'/../config/log_elasticsearch.php', 'log_elasticsearch');
        $this->mergeConfigFrom(__DIR__.'/../config/activity_logs.php', 'activity_logs');
        $this->configureIndexNames();

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

        $this->app->singleton(HttpPayloadRedactorResolver::class, fn (Application $app): HttpPayloadRedactorResolver => new HttpPayloadRedactorResolver(
            defaultRedactor: $app->make(SensitiveDataRedactor::class),
            paymentRedactor: $app->make(PaymentRedactor::class),
        ));

        $this->app->singleton(HttpLogger::class, fn (Application $app): HttpLogger => new HttpLogger(
            redactor: $app->make(SensitiveDataRedactor::class),
            redactorResolver: $app->make(HttpPayloadRedactorResolver::class),
            failureReporter: $app->make(AuditFailureReporter::class),
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

        $this->app->singleton(ActivityLogger::class, fn (Application $app): ActivityLogger => new ActivityLogger(
            redactor: new SensitiveDataRedactor(body: $this->redactionRules('activity_logs.redaction')),
            failureReporter: $app->make(AuditFailureReporter::class),
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

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/http_logs.php'         => config_path('http_logs.php'),
                __DIR__.'/../config/log_elasticsearch.php' => config_path('log_elasticsearch.php'),
                __DIR__.'/../config/activity_logs.php'     => config_path('activity_logs.php'),
                __DIR__.'/../stubs/Enums/ElasticAudit'     => app_path('Enums/ElasticAudit'),
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
            'http_logs.index_alias'                   => "{$prefix}_http_logs",
            'http_logs.index_alias_write'             => "{$prefix}_http_logs_write",
            'activity_logs.index_alias'               => "{$prefix}_activity_logs",
            'activity_logs.index_alias_write'         => "{$prefix}_activity_logs_write",
            'log_elasticsearch.lifecycle.policy_name' => "{$prefix}_elastic_audit_policy",
        ];

        foreach ($derived as $key => $value) {
            if ($config->get($key) === null) {
                $config->set($key, $value);
            }
        }
    }

    private function registerDashboardAssetRoute(): void
    {
        $httpDashboardEnabled     = (bool) ($this->app['config']['http_logs']['dashboard']['enabled'] ?? false);
        $activityDashboardEnabled = (bool) ($this->app['config']['activity_logs']['dashboard']['enabled'] ?? false);

        if (! $httpDashboardEnabled && ! $activityDashboardEnabled) {
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

    /**
     * Build the route group prefix from an optional shared group segment and the
     * dashboard's own subpath, tolerating empty/slash-padded values.
     */
    private function composeDashboardPrefix(array $dashboard, string $defaultPath): string
    {
        $prefix = trim((string) ($dashboard['prefix'] ?? ''), '/');
        $path   = trim((string) ($dashboard['path'] ?? $defaultPath), '/');

        return trim($prefix.'/'.$path, '/');
    }
}
