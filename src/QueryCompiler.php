<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Contracts\QueryCompilerInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\ParallelQuery;
use Kosadchiy\LaravelParallelDb\Exceptions\QueryCompilationException;
use Kosadchiy\LaravelParallelDb\Support\SqlTypeDetector;

final readonly class QueryCompiler implements QueryCompilerInterface
{
    public function __construct(
        private DatabaseManager $database,
        private ConnectionConfigResolver $configResolver,
    ) {
    }

    public function compileMany(array $queries, ?string $defaultConnection = null): array
    {
        $compiled = [];

        foreach ($queries as $key => $query) {
            $compiled[] = $this->compileSingle((string) $key, $query, $defaultConnection);
        }

        return $compiled;
    }

    private function compileSingle(string $key, mixed $query, ?string $defaultConnection): CompiledQuery
    {
        if ($query instanceof QueryBuilder || $query instanceof EloquentBuilder) {
            return $this->compileBuilder($key, $query, $defaultConnection);
        }

        if ($query instanceof ParallelQuery) {
            return $this->compileSql(
                key: $key,
                sql: $query->sql,
                bindings: $query->bindings,
                connection: $query->connection,
                defaultConnection: $defaultConnection,
                metadata: $query->metadata,
            );
        }

        if (is_array($query) && isset($query['sql']) && is_string($query['sql'])) {
            return $this->compileSql(
                key: $key,
                sql: $query['sql'],
                bindings: isset($query['bindings']) && is_array($query['bindings']) ? $query['bindings'] : [],
                connection: isset($query['connection']) && is_string($query['connection']) ? $query['connection'] : null,
                defaultConnection: $defaultConnection,
                metadata: isset($query['metadata']) && is_array($query['metadata']) ? $query['metadata'] : [],
            );
        }

        throw new QueryCompilationException(sprintf(
            'Unsupported query payload for key [%s]: %s',
            $key,
            get_debug_type($query),
        ));
    }

    private function compileBuilder(string $key, QueryBuilder|EloquentBuilder $builder, ?string $defaultConnection): CompiledQuery
    {
        $base = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        $connection = $base->getConnection();

        $connectionName = $connection->getName()
            ?? $defaultConnection
            ?? $this->configResolver->defaultConnection();

        $sql = $base->toSql();
        $bindings = $connection->prepareBindings($base->getBindings());

        return new CompiledQuery(
            key: $key,
            sql: $sql,
            bindings: $bindings,
            type: SqlTypeDetector::detect($sql),
            connection: $connectionName,
            driver: $connection->getDriverName(),
            metadata: [],
        );
    }

    /**
     * @param array<int, mixed> $bindings
     * @param array<string, mixed> $metadata
     */
    private function compileSql(
        string $key,
        string $sql,
        array $bindings,
        ?string $connection,
        ?string $defaultConnection,
        array $metadata,
    ): CompiledQuery {
        $connectionName = $connection
            ?? $defaultConnection
            ?? $this->configResolver->defaultConnection();

        $driver = $this->configResolver->connectionDriver($connectionName);

        return new CompiledQuery(
            key: $key,
            sql: $sql,
            bindings: $bindings,
            type: SqlTypeDetector::detect($sql),
            connection: $connectionName,
            driver: $driver,
            metadata: $metadata,
        );
    }
}
