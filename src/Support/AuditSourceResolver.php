<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;
use Tsitsishvili\ElasticAudit\DataTransferObjects\AuditSource;
use Tsitsishvili\ElasticAudit\DataTransferObjects\ExecutionOrigin;

final class AuditSourceResolver
{
    /** @var list<string> */
    private array $queueJobs = [];

    /** @var list<string> */
    private array $consoleCommands = [];

    public function __construct(
        private readonly ?ContainerContract $container = null,
    ) {}

    public static function fromContainer(): self
    {
        $container = Container::getInstance();

        if ($container->bound(self::class)) {
            return $container->make(self::class);
        }

        return new self($container);
    }

    public function resolve(
        ?ExecutionOrigin $override = null,
        ?Request $request = null,
    ): AuditSource {
        return new AuditSource(
            serviceName: $this->serviceName(),
            serviceEnvironment: $this->serviceEnvironment(),
            execution: $override ?? $this->executionOrigin($request),
        );
    }

    public function enterQueueJob(string $name): void
    {
        $this->queueJobs[] = $this->nullableString($name) ?? ExecutionOrigin::TYPE_UNKNOWN;
    }

    public function leaveQueueJob(): void
    {
        array_pop($this->queueJobs);
    }

    public function enterConsoleCommand(string $name): void
    {
        $this->consoleCommands[] = $this->nullableString($name) ?? ExecutionOrigin::TYPE_UNKNOWN;
    }

    public function leaveConsoleCommand(): void
    {
        array_pop($this->consoleCommands);
    }

    private function serviceName(): string
    {
        return $this->nullableString($this->config('app.name', 'app')) ?? 'app';
    }

    private function serviceEnvironment(): ?string
    {
        return $this->nullableString($this->config('app.env', null));
    }

    /**
     * Read config from the injected container so a resolver built against a
     * specific application reports that application's identity.
     */
    private function config(string $key, mixed $default): mixed
    {
        $container = $this->container ?? Container::getInstance();

        if (! $container->bound('config')) {
            return $default;
        }

        return $container->make('config')->get($key, $default);
    }

    private function executionOrigin(?Request $request = null): ExecutionOrigin
    {
        $queueJob = end($this->queueJobs);

        if (is_string($queueJob)) {
            return new ExecutionOrigin(
                type: ExecutionOrigin::TYPE_QUEUE,
                name: $queueJob,
            );
        }

        $consoleCommand = end($this->consoleCommands);

        if (is_string($consoleCommand)) {
            return new ExecutionOrigin(
                type: ExecutionOrigin::TYPE_CONSOLE,
                name: $consoleCommand,
            );
        }

        $requestOrigin = $this->requestOrigin($request);

        if ($requestOrigin !== null) {
            return $requestOrigin;
        }

        return ExecutionOrigin::unknown();
    }

    private function requestOrigin(?Request $request = null): ?ExecutionOrigin
    {
        // A caller-supplied request is always a real inbound request. A container-bound
        // one is not: Laravel's SetRequestForConsole bootstrapper binds a synthetic
        // request built from app.url in every Artisan process, so an unrouted container
        // request inside a console process is evidence of nothing.
        $supplied = $request !== null;

        if (! $supplied) {
            if ($this->container === null || ! $this->container->bound('request')) {
                return null;
            }

            try {
                $request = $this->container->make('request');
            } catch (Throwable) {
                return $this->unroutedOrigin($supplied);
            }
        }

        try {
            $route = $request->route();

            if (! $route instanceof Route) {
                return $this->unroutedOrigin($supplied);
            }

            $name = $this->nullableString($route->getName())
                ?? $this->nullableString($route->uri());
            $action = $this->nullableString($route->getActionName());

            // Routes built directly carry their handler under `uses` only; the router
            // copies it to `controller`. Closure handlers resolve to neither.
            if ($action === 'Closure') {
                $action = $this->nullableString($route->getAction('controller'))
                    ?? $this->nullableString($route->getAction('uses'));
            }

            return new ExecutionOrigin(
                type: ExecutionOrigin::TYPE_HTTP,
                name: $name,
                action: $action,
            );
        } catch (Throwable) {
            return $this->unroutedOrigin($supplied);
        }
    }

    /**
     * A request carrying no resolved route only proves HTTP execution outside a
     * console process; inside one it is the synthetic console request.
     */
    private function unroutedOrigin(bool $supplied): ?ExecutionOrigin
    {
        if ($supplied || ! $this->runningInConsole()) {
            return new ExecutionOrigin(ExecutionOrigin::TYPE_HTTP);
        }

        return null;
    }

    private function runningInConsole(): bool
    {
        return $this->container instanceof ApplicationContract
            && $this->container->runningInConsole();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
