# laravel-parallel-db

Laravel package for in-process parallel SQL execution using native async database APIs:

- PostgreSQL: `ext-pgsql` (`pg_send_query_params`, `pg_socket`, `pg_consume_input`, `pg_connection_busy`, `pg_get_result`)
- MySQL: `ext-mysqli` (`MYSQLI_ASYNC`, `mysqli_poll`, `reap_async_query`)

No `fork`, no child processes, no queues, no ReactPHP/Amp/Swoole/RoadRunner.

## Why

When one request needs multiple independent SQL calls, sequential execution adds latency. This package runs them in parallel on separate DB connections in the same PHP process.

## High-level design

### Core components

- `ParallelDatabaseManager` - Laravel-facing entrypoint, accepts mixed query inputs.
- `QueryCompiler` - compiles builder/raw payloads into `CompiledQuery`.
- `ParallelExecutor` - bounded scheduler + timeout + result collection.
- `DriverRegistry` - maps DB driver names to async implementations.
- `PostgresAsyncDriver`, `MySqlAsyncDriver` - native async transport.
- `ConnectionConfigResolver` + `*ConnectionFactory` - per-query dedicated connection creation.

### DTOs

- `CompiledQuery`
- `RunningQuery`
- `QueryResult`
- `ParallelQuery`
- `ParallelOptions`

### Execution flow

1. User passes map of queries (`Builder`, `Eloquent`, raw SQL array, or `ParallelQuery`).
2. Compiler normalizes all inputs to `CompiledQuery`.
3. Executor keeps pending/running sets and starts up to `maxConcurrency` queries.
4. Drivers poll readiness (`stream_select()` for PostgreSQL sockets, `mysqli_poll()` for MySQL links).
5. Ready queries are reaped and transformed into `QueryResult`.
6. Executor continues until all queries are finished or timeout is reached.

## Installation

```bash
composer require kosadchiy/laravel-parallel-db
```

Optional extensions by driver:

- `ext-pgsql`
- `ext-mysqli`

Publish config:

```bash
php artisan vendor:publish --tag=parallel-db-config
```

## Configuration

```php
return [
    'default_max_concurrency' => 3,
    'default_timeout_ms' => 500,
    'error_mode' => 'fail_fast', // fail_fast|collect

    'drivers' => [
        'pgsql' => ['enabled' => true],
        'mysql' => ['enabled' => true],
    ],
];
```

## Laravel API

### Default DB manager path

```php
$result = DB::parallel()->run([
    'users' => DB::table('users')->where('active', true),
    'servers' => DB::table('servers')->where('status', 'ok'),
]);
```

### With options

```php
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;

$result = DB::parallel(
    maxConcurrency: 3,
    timeoutMs: 1000,
    errorMode: ErrorMode::COLLECT,
)->run([
    'users' => User::query()->where('active', true),
    'servers' => Server::query()->where('status', 'ok'),
]);
```

### Via specific connection

```php
$result = DB::connection('pgsql')->parallel()->run([
    'users' => ['sql' => 'select * from users where active = ?', 'bindings' => [true]],
]);
```

### Explicit `ParallelQuery`

```php
use Kosadchiy\LaravelParallelDb\DTO\ParallelQuery;

$result = DB::parallel()->run([
    'audit' => new ParallelQuery(
        sql: 'insert into audit_log(message, created_at) values (?, ?)',
        bindings: ['parallel run', now()],
        connection: 'mysql',
    ),
]);
```

## Result format

Each input key maps to `QueryResult`:

- `sql`
- `bindings`
- `type`
- `rows`
- `rowCount`
- `lastInsertId`
- `durationMs`
- `connectionDriver`
- `success`
- `error`

## Error behavior

- `fail_fast`: first failed query stops the batch and throws `ParallelQueryFailedException`.
- `collect`: executor continues and returns failed entries with `success=false`.

## Write-query caveats

Writes are allowed (`INSERT/UPDATE/DELETE/DDL`), but:

- every query uses a separate connection;
- no shared transaction across the batch;
- global rollback is impossible;
- commit ordering across queries is not guaranteed.

Use parallel writes only when this behavior is acceptable.

## Driver notes

### PostgreSQL

- Uses parameterized async calls (`pg_send_query_params`) with `?` -> `$1..$N` conversion.
- Wait uses socket readiness (`pg_socket` + `stream_select`).

### MySQL

- Uses `MYSQLI_ASYNC` + `mysqli_poll` + `reap_async_query`.
- Async prepared statements are not used in v1.
- Bindings are interpolated with `mysqli::real_escape_string` and strict typing rules.
- `?` inside SQL literals can confuse placeholder counting in v1.

## Limitations and controversial points

1. Async API parity differs between `ext-pgsql` and `ext-mysqli`.
2. MySQL v1 binding interpolation is pragmatic, not perfect SQL parser behavior.
3. `stream_select()` has platform limits and FD-scaling caveats for very large sets.
4. Result hydration is low-level arrays, not Eloquent model hydration.
5. Parallel writes are independent operations, not atomic group transaction.

## Testing

Unit tests cover:

- query compilation from Builder/Eloquent/raw;
- bounded concurrency;
- timeout handling;
- fail-fast and collect-errors behavior;
- key-preserving result mapping.

Run:

```bash
composer test
```

Integration tests with real DB engines are intentionally opt-in (`tests/Integration`).

## Future enhancements

- safe read-only mode;
- driver capability flags;
- retry policy;
- connection pooling;
- richer query lifecycle events/metrics.
