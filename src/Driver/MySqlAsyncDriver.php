<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Driver;

use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionFactoryInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use Kosadchiy\LaravelParallelDb\Support\MysqlBindingInterpolator;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

final readonly class MySqlAsyncDriver implements AsyncDriverInterface
{
    public function __construct(
        private ConnectionFactoryInterface $connectionFactory,
        private ConnectionConfigResolver $configResolver,
    ) {
    }

    public function name(): string
    {
        return 'mysql';
    }

    public function start(CompiledQuery $query): RunningQuery
    {
        $connection = $this->connectionFactory->create($this->configResolver->connectionConfig($query->connection));

        if (!$connection instanceof mysqli) {
            throw new ParallelExecutionException('MySQL async driver expects mysqli connection.');
        }

        $sql = MysqlBindingInterpolator::interpolate($connection, $query->sql, $query->bindings);

        $sent = $connection->query($sql, MYSQLI_ASYNC);

        if ($sent !== true) {
            $error = $connection->error !== '' ? $connection->error : 'Unknown MySQL async error';
            $this->connectionFactory->close($connection);
            throw new ParallelExecutionException($error);
        }

        return new RunningQuery(
            query: $query,
            driver: $this->name(),
            connectionHandle: $connection,
            socket: null,
            startedAt: microtime(true),
        );
    }

    public function poll(array $runningQueries, int $timeoutMs): array
    {
        if ($runningQueries === []) {
            return [];
        }

        $links = [];
        $indexByObjectId = [];

        foreach ($runningQueries as $key => $runningQuery) {
            $connection = $runningQuery->connectionHandle;
            if ($connection instanceof mysqli) {
                $links[] = $connection;
                $indexByObjectId[spl_object_id($connection)] = $key;
            }
        }

        if ($links === []) {
            return [];
        }

        $errors = $links;
        $reject = $links;
        $seconds = intdiv(max($timeoutMs, 0), 1000);
        $microseconds = (max($timeoutMs, 0) % 1000) * 1000;

        $readyCount = mysqli_poll($links, $errors, $reject, $seconds, $microseconds);

        if ($readyCount === false || $readyCount === 0) {
            return [];
        }

        $ready = [];
        foreach ($links as $connection) {
            $id = spl_object_id($connection);
            if (isset($indexByObjectId[$id])) {
                $ready[] = $indexByObjectId[$id];
            }
        }

        return $ready;
    }

    public function collect(RunningQuery $runningQuery): QueryResult
    {
        $connection = $runningQuery->connectionHandle;
        $query = $runningQuery->query;

        if (!$connection instanceof mysqli) {
            throw new ParallelExecutionException('MySQL async driver expects mysqli connection.');
        }

        $durationMs = (microtime(true) - $runningQuery->startedAt) * 1000;

        try {
            $result = $connection->reap_async_query();
        } catch (mysqli_sql_exception $exception) {
            return $this->failureResult($query, $durationMs, $exception->getMessage());
        }

        if ($result === false) {
            return $this->failureResult(
                $query,
                $durationMs,
                $connection->error ?: 'Unknown MySQL async error',
            );
        }

        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $rowCount = (int) $result->num_rows;
            $result->free();

            return new QueryResult(
                sql: $query->sql,
                bindings: $query->bindings,
                type: $query->type,
                rows: $rows,
                rowCount: $rowCount,
                lastInsertId: null,
                durationMs: $durationMs,
                connectionDriver: $query->driver,
                success: true,
                error: null,
            );
        }

        $insertId = $connection->insert_id > 0 ? (string) $connection->insert_id : null;

        return new QueryResult(
            sql: $query->sql,
            bindings: $query->bindings,
            type: $query->type,
            rows: [],
            rowCount: (int) $connection->affected_rows,
            lastInsertId: $insertId,
            durationMs: $durationMs,
            connectionDriver: $query->driver,
            success: true,
            error: null,
        );
    }

    public function close(RunningQuery $runningQuery): void
    {
        $this->connectionFactory->close($runningQuery->connectionHandle);
    }

    private function failureResult(CompiledQuery $query, float $durationMs, string $error): QueryResult
    {
        return QueryResult::failure(
            sql: $query->sql,
            bindings: $query->bindings,
            type: $query->type,
            connectionDriver: $query->driver,
            durationMs: $durationMs,
            error: $error,
        );
    }
}
