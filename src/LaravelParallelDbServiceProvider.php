<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionPool;
use Kosadchiy\LaravelParallelDb\Connection\MySqlConnectionFactory;
use Kosadchiy\LaravelParallelDb\Connection\PooledConnectionFactory;
use Kosadchiy\LaravelParallelDb\Connection\PostgresConnectionFactory;
use Kosadchiy\LaravelParallelDb\Driver\DriverRegistry;
use Kosadchiy\LaravelParallelDb\Driver\MySqlAsyncDriver;
use Kosadchiy\LaravelParallelDb\Driver\PostgresAsyncDriver;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;

final class LaravelParallelDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/parallel-db.php', 'parallel-db');

        $this->app->singleton(ConnectionConfigResolver::class, fn ($app) => new ConnectionConfigResolver($app['config']));
        $this->app->singleton(ConnectionPool::class, fn ($app) => new ConnectionPool(
            enabled: (bool) $app['config']->get('parallel-db.pool.enabled', true),
            maxIdlePerKey: (int) $app['config']->get('parallel-db.pool.max_idle_per_key', 10),
        ));

        $this->app->singleton('parallel-db.connection-factory.pgsql', fn ($app) => new PooledConnectionFactory(
            inner: new PostgresConnectionFactory(),
            pool: $app->make(ConnectionPool::class),
        ));

        $this->app->singleton('parallel-db.connection-factory.mysql', fn ($app) => new PooledConnectionFactory(
            inner: new MySqlConnectionFactory(),
            pool: $app->make(ConnectionPool::class),
        ));

        $this->app->singleton(DriverRegistry::class, function ($app) {
            $registry = new DriverRegistry();

            if ($app['config']->get('parallel-db.drivers.pgsql.enabled', true)) {
                $registry->register(new PostgresAsyncDriver(
                    connectionFactory: $app->make('parallel-db.connection-factory.pgsql'),
                    configResolver: $app->make(ConnectionConfigResolver::class),
                ));
            }

            if ($app['config']->get('parallel-db.drivers.mysql.enabled', true)) {
                $registry->register(new MySqlAsyncDriver(
                    connectionFactory: $app->make('parallel-db.connection-factory.mysql'),
                    configResolver: $app->make(ConnectionConfigResolver::class),
                ));
            }

            return $registry;
        });

        $this->app->singleton(QueryCompiler::class, fn ($app) => new QueryCompiler(
            configResolver: $app->make(ConnectionConfigResolver::class),
        ));

        $this->app->singleton(ParallelExecutor::class, fn ($app) => new ParallelExecutor(
            drivers: $app->make(DriverRegistry::class),
        ));

        $this->app->bind(ParallelDatabaseManager::class, function ($app) {
            $errorMode = ErrorMode::tryFrom((string) $app['config']->get('parallel-db.error_mode', ErrorMode::FAIL_FAST->value))
                ?? ErrorMode::FAIL_FAST;

            return new ParallelDatabaseManager(
                compiler: $app->make(QueryCompiler::class),
                executor: $app->make(ParallelExecutor::class),
                options: new ParallelOptions(
                    maxConcurrency: (int) $app['config']->get('parallel-db.default_max_concurrency', 3),
                    timeoutMs: (int) $app['config']->get('parallel-db.default_timeout_ms', 500),
                    errorMode: $errorMode,
                ),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/parallel-db.php' => config_path('parallel-db.php'),
        ], 'parallel-db-config');

        DatabaseManager::macro('parallel', function (
            ?int $maxConcurrency = null,
            ?int $timeoutMs = null,
            ErrorMode|string|null $errorMode = null,
        ) {
            /** @var DatabaseManager $this */
            $mode = $errorMode instanceof ErrorMode
                ? $errorMode
                : ErrorMode::tryFrom((string) ($errorMode ?? ''));

            return app(ParallelDatabaseManager::class)->withOptions(
                maxConcurrency: $maxConcurrency,
                timeoutMs: $timeoutMs,
                errorMode: $mode,
                connection: $this->getDefaultConnection(),
            );
        });

        Connection::macro('parallel', function (
            ?int $maxConcurrency = null,
            ?int $timeoutMs = null,
            ErrorMode|string|null $errorMode = null,
        ) {
            /** @var Connection $this */
            $mode = $errorMode instanceof ErrorMode
                ? $errorMode
                : ErrorMode::tryFrom((string) ($errorMode ?? ''));

            return app(ParallelDatabaseManager::class)->withOptions(
                maxConcurrency: $maxConcurrency,
                timeoutMs: $timeoutMs,
                errorMode: $mode,
                connection: $this->getName(),
            );
        });
    }
}
