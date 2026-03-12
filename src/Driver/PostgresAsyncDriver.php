<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Driver;

use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionFactoryInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use Kosadchiy\LaravelParallelDb\Support\PlaceholderConverter;

final readonly class PostgresAsyncDriver implements AsyncDriverInterface
{
    public function __construct(
        private ConnectionFactoryInterface $connectionFactory,
        private ConnectionConfigResolver $configResolver,
    ) {
    }

    public function name(): string
    {
        return 'pgsql';
    }

    public function start(CompiledQuery $query): RunningQuery
    {
        $connection = $this->connectionFactory->create($this->configResolver->connectionConfig($query->connection));

        $converted = PlaceholderConverter::questionMarksToPgParams($query->sql, $query->bindings);

        $sent = count($converted['bindings']) > 0
            ? @pg_send_query_params($connection, $converted['sql'], $converted['bindings'])
            : @pg_send_query($connection, $converted['sql']);

        if ($sent !== true) {
            $error = pg_last_error($connection) ?: 'Unknown PostgreSQL async error';
            $this->connectionFactory->close($connection);
            throw new ParallelExecutionException($error);
        }

        $socket = pg_socket($connection);

        if ($socket === false) {
            $this->connectionFactory->close($connection);
            throw new ParallelExecutionException('Unable to acquire PostgreSQL socket.');
        }

        return new RunningQuery(
            query: $query,
            driver: $this->name(),
            connectionHandle: $connection,
            socket: $socket,
            startedAt: microtime(true),
        );
    }

    public function poll(array $runningQueries, int $timeoutMs): array
    {
        if ($runningQueries === []) {
            return [];
        }

        $read = [];
        $bySocketId = [];

        foreach ($runningQueries as $key => $runningQuery) {
            if (is_resource($runningQuery->socket)) {
                $read[] = $runningQuery->socket;
                $bySocketId[(int) $runningQuery->socket] = $key;
            }
        }

        if ($read === []) {
            return [];
        }

        $write = null;
        $except = null;
        $seconds = intdiv(max($timeoutMs, 0), 1000);
        $microseconds = (max($timeoutMs, 0) % 1000) * 1000;

        $selected = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($selected === false || $selected === 0) {
            return [];
        }

        $ready = [];
        foreach ($read as $socket) {
            $id = (int) $socket;
            if (isset($bySocketId[$id])) {
                $ready[] = $bySocketId[$id];
            }
        }

        return $ready;
    }

    public function collect(RunningQuery $runningQuery): QueryResult
    {
        $connection = $runningQuery->connectionHandle;
        $query = $runningQuery->query;

        @pg_consume_input($connection);

        while (@pg_connection_busy($connection)) {
            @pg_consume_input($connection);
            usleep(500);
        }

        $lastResult = null;

        while (true) {
            $result = @pg_get_result($connection);
            if ($result === false) {
                break;
            }

            $lastResult = $result;
        }

        $durationMs = (microtime(true) - $runningQuery->startedAt) * 1000;

        if ($lastResult === null) {
            return QueryResult::failure(
                sql: $query->sql,
                bindings: $query->bindings,
                type: $query->type,
                connectionDriver: $query->driver,
                durationMs: $durationMs,
                error: 'PostgreSQL query produced no result object.',
            );
        }

        $status = pg_result_status($lastResult);
        $ok = in_array($status, [PGSQL_COMMAND_OK, PGSQL_TUPLES_OK], true);

        if (!$ok) {
            return QueryResult::failure(
                sql: $query->sql,
                bindings: $query->bindings,
                type: $query->type,
                connectionDriver: $query->driver,
                durationMs: $durationMs,
                error: pg_result_error($lastResult) ?: 'Unknown PostgreSQL async error',
            );
        }

        $rows = [];
        if ($status === PGSQL_TUPLES_OK) {
            $rows = pg_fetch_all($lastResult, PGSQL_ASSOC) ?: [];
        }

        $rowCount = $status === PGSQL_TUPLES_OK
            ? count($rows)
            : pg_affected_rows($lastResult);

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

    public function close(RunningQuery $runningQuery): void
    {
        $this->connectionFactory->close($runningQuery->connectionHandle);
    }
}
