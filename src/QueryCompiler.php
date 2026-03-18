<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Contracts\QueryCompilerInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\Exceptions\QueryCompilationException;
use Kosadchiy\LaravelParallelDb\Support\SqlTypeDetector;

final readonly class QueryCompiler implements QueryCompilerInterface
{
    public function __construct(private ConnectionConfigResolver $configResolver)
    {
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
        if ($query instanceof Closure) {
            $query = $query();
        }

        if ($query instanceof QueryBuilder || $query instanceof EloquentBuilder) {
            return $this->compileBuilder($key, $query, $defaultConnection);
        }

        throw new QueryCompilationException(sprintf(
            'Unsupported query payload for key [%s]: %s. Expected Query Builder, Eloquent Builder, or Closure returning one of them.',
            $key,
            get_debug_type($query),
        ));
    }

    /**
     * @param QueryBuilder|EloquentBuilder<\Illuminate\Database\Eloquent\Model> $builder
     */
    private function compileBuilder(string $key, QueryBuilder|EloquentBuilder $builder, ?string $defaultConnection): CompiledQuery
    {
        $base = $builder instanceof EloquentBuilder ? $builder->getQuery() : $builder;
        /** @var Connection $connection */
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
        );
    }
}
