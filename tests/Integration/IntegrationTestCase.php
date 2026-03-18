<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\Enum\QueryType;
use Kosadchiy\LaravelParallelDb\LaravelParallelDbServiceProvider;
use Kosadchiy\LaravelParallelDb\ParallelDatabaseManager;
use Kosadchiy\LaravelParallelDb\ParallelExecutor;
use Orchestra\Testbench\TestCase;
use Throwable;

abstract class IntegrationTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelParallelDbServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => getenv('TEST_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_PGSQL_PORT') ?: 5432),
            'database' => getenv('TEST_PGSQL_DATABASE') ?: 'laravel_parallel_db',
            'username' => getenv('TEST_PGSQL_USERNAME') ?: 'laravel_parallel_db',
            'password' => getenv('TEST_PGSQL_PASSWORD') ?: 'secret',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_MYSQL_PORT') ?: 3306),
            'database' => getenv('TEST_MYSQL_DATABASE') ?: 'laravel_parallel_db',
            'username' => getenv('TEST_MYSQL_USERNAME') ?: 'laravel_parallel_db',
            'password' => getenv('TEST_MYSQL_PASSWORD') ?: 'secret',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);
        $app['config']->set('parallel-db.pool.enabled', false);
        $app['config']->set('parallel-db.default_timeout_ms', 2000);
        $app['config']->set('parallel-db.default_max_concurrency', 2);
    }

    protected function requireConnection(string $connection): void
    {
        $requiredExtension = match ($connection) {
            'pgsql' => 'pgsql',
            'mysql' => 'mysqli',
            default => null,
        };

        if ($requiredExtension !== null && !extension_loaded($requiredExtension)) {
            self::markTestSkipped(sprintf('The %s extension is not loaded.', $requiredExtension));
        }

        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable $throwable) {
            self::markTestSkipped(sprintf(
                'Integration database connection [%s] is unavailable: %s',
                $connection,
                $throwable->getMessage(),
            ));
        }
    }

    protected function recreateUsersTable(string $connection): void
    {
        $schema = Schema::connection($connection);

        $schema->dropIfExists('parallel_test_users');
        $schema->create('parallel_test_users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(true);
        });
    }

    protected function parallelManager(string $connection): ParallelDatabaseManager
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app->make(ParallelDatabaseManager::class)->withOptions(connection: $connection);
    }

    protected function parallelExecutor(): ParallelExecutor
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app->make(ParallelExecutor::class);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function compiledQuery(
        string $key,
        string $sql,
        array $bindings,
        QueryType $type,
        string $connection,
    ): CompiledQuery {
        $preparedBindings = DB::connection($connection)->prepareBindings($bindings);

        return new CompiledQuery(
            key: $key,
            sql: $sql,
            bindings: $preparedBindings,
            type: $type,
            connection: $connection,
            driver: $connection,
        );
    }

    protected function defaultParallelOptions(string $connection): ParallelOptions
    {
        return new ParallelOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            connection: $connection,
        );
    }
}
