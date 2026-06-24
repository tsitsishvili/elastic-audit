<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit;

use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Tsitsishvili\ElasticAudit\Console\CreateActivityLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\CreateHttpLogIndexCommand;
use Tsitsishvili\ElasticAudit\Console\PruneActivityLogCommand;
use Tsitsishvili\ElasticAudit\Console\PruneHttpLogCommand;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;
use Tsitsishvili\ElasticAudit\DataTransferObjects\RedactionRules;
use Tsitsishvili\ElasticAudit\Http\Middleware\AuthorizeDashboard;
use Tsitsishvili\ElasticAudit\Http\HttpLogClientFactory;
use Tsitsishvili\ElasticAudit\Services\ActivityLogger;
use Tsitsishvili\ElasticAudit\Services\ActivityLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClient;
use Tsitsishvili\ElasticAudit\Services\Elasticsearch\LogElasticsearchClientInterface;
use Tsitsishvili\ElasticAudit\Services\HttpLogIndexer;
use Tsitsishvili\ElasticAudit\Services\Redactors\PaymentRedactor;
use Tsitsishvili\ElasticAudit\Services\Redactors\SensitiveDataRedactor;
use Tsitsishvili\ElasticAudit\HttpLogManager;

class ElasticAuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/http_logs.php', 'http_logs');
        $this->mergeConfigFrom(__DIR__ . '/../config/log_elasticsearch.php', 'log_elasticsearch');
        $this->mergeConfigFrom(__DIR__ . '/../config/activity_logs.php', 'activity_logs');

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

        $this->app->singleton(SensitiveDataRedactor::class, fn(): SensitiveDataRedactor => new SensitiveDataRedactor(
            headers: $this->redactionRules('http_logs.redaction.headers'),
            body: $this->redactionRules('http_logs.redaction.body'),
        ));

        $this->app->singleton(PaymentRedactor::class, fn(): PaymentRedactor => new PaymentRedactor(
            headers: $this->redactionRules('http_logs.redaction.headers'),
            body: $this->redactionRules('http_logs.redaction.body'),
        ));

        $this->app->singleton(HttpLogClientFactory::class);
        $this->app->singleton(HttpLogManager::class);

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

        $this->app->singleton(ActivityLogger::class, fn(): ActivityLogger => new ActivityLogger(
            new SensitiveDataRedactor(body: $this->redactionRules('activity_logs.redaction')),
        ));

        $this->app->singleton(ActivityDashboardQuery::class, function (Application $app) {
            return new ActivityDashboardQuery(
                client: $app->make(LogElasticsearchClientInterface::class),
                readAlias: $app['config']['activity_logs']['index_alias'],
            );
        });
    }

    /**
     * Build a RedactionRules from the 'allow'/'block' arrays under a config key.
     */
    private function redactionRules(string $configKey): RedactionRules
    {
        return new RedactionRules(
            allow: (array)config("{$configKey}.allow", []),
            block: (array)config("{$configKey}.block", []),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'elastic-audit');

        $this->registerDashboardRoutes();
        $this->registerActivityDashboardRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/http_logs.php'         => config_path('http_logs.php'),
                __DIR__ . '/../config/log_elasticsearch.php' => config_path('log_elasticsearch.php'),
                __DIR__ . '/../config/activity_logs.php'     => config_path('activity_logs.php'),
                __DIR__ . '/Stubs/Enums/ElasticAudit'        => app_path('Enums/ElasticAudit'),
            ], 'elastic-audit');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/elastic-audit'),
            ], 'elastic-audit-views');
        }

        $this->commands([
            CreateHttpLogIndexCommand::class,
            PruneHttpLogCommand::class,
            CreateActivityLogIndexCommand::class,
            PruneActivityLogCommand::class,
        ]);
    }

    private function registerDashboardRoutes(): void
    {
        $dashboard = $this->app['config']['http_logs']['dashboard'] ?? [];

        if (($dashboard['enabled'] ?? false) === false) {
            return;
        }

        Route::group([
            'prefix'     => $this->composeDashboardPrefix($dashboard, 'http-logs'),
            'middleware' => array_merge((array)($dashboard['middleware'] ?? ['web']), [AuthorizeDashboard::class]),
            'as'         => 'http-logs.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/dashboard.php');
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
            'middleware' => array_merge((array)($dashboard['middleware'] ?? ['web']), [AuthorizeDashboard::class]),
            'as'         => 'activity-logs.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/activity_dashboard.php');
        });
    }

    /**
     * Build the route group prefix from an optional shared group segment and the
     * dashboard's own subpath, tolerating empty/slash-padded values.
     */
    private function composeDashboardPrefix(array $dashboard, string $defaultPath): string
    {
        $prefix = trim((string)($dashboard['prefix'] ?? ''), '/');
        $path   = trim((string)($dashboard['path'] ?? $defaultPath), '/');

        return trim($prefix . '/' . $path, '/');
    }
}
