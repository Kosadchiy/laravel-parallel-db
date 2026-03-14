<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Driver;

use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionFactoryInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use Kosadchiy\LaravelParallelDb\Support\PostgresPlaceholderConverter;

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

        $converted = PostgresPlaceholderConverter::questionMarksToPgParams($query->sql, $query->bindings);

        $sent = count($converted['bindings']) > 0
            ? $this->capturePgError(
                static fn () => pg_send_query_params($connection, $converted['sql'], $converted['bindings']),
                'Unable to send PostgreSQL async query.',
            )
            : $this->capturePgError(
                static fn () => pg_send_query($connection, $converted['sql']),
                'Unable to send PostgreSQL async query.',
            );

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

        $selected = $this->streamSelect($read, $write, $except, $seconds, $microseconds);

        if ($selected === 0) {
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

        $this->consumeInput($connection);

        while ($this->connectionBusy($connection)) {
            $this->consumeInput($connection);
            usleep(500);
        }

        $lastResult = null;

        while (true) {
            $result = $this->getResult($connection);
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

    private function consumeInput(mixed $connection): void
    {
        $result = $this->capturePgError(
            static fn () => pg_consume_input($connection),
            'Unable to consume PostgreSQL socket input.',
        );

        if ($result !== true) {
            throw new ParallelExecutionException('Unable to consume PostgreSQL socket input.');
        }
    }

    private function connectionBusy(mixed $connection): bool
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = pg_connection_busy($connection);
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            throw new ParallelExecutionException($warning);
        }

        return $result;
    }

    private function getResult(mixed $connection): mixed
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = pg_get_result($connection);
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            throw new ParallelExecutionException($warning);
        }

        return $result;
    }

    private function capturePgError(callable $callback, string $fallbackMessage): mixed
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new ParallelExecutionException($warning ?? $fallbackMessage);
        }

        return $result;
    }

    private function streamSelect(array &$read, mixed &$write, mixed &$except, int $seconds, int $microseconds): int
    {
        $warning = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = stream_select($read, $write, $except, $seconds, $microseconds);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            throw new ParallelExecutionException($warning ?? 'stream_select failed while polling PostgreSQL sockets.');
        }

        return $result;
    }
}
