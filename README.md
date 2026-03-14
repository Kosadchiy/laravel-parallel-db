# laravel-parallel-db

Laravel package for in-process parallel SQL execution using native async database APIs:

- PostgreSQL: `ext-pgsql` (`pg_send_query_params`, `pg_socket`, `pg_consume_input`, `pg_connection_busy`, `pg_get_result`)
- MySQL: `ext-mysqli` (`MYSQLI_ASYNC`, `mysqli_poll`, `reap_async_query`)

No child processes, no queues, no ReactPHP, Amp, or Swoole.

## Why

When one request needs multiple independent SQL calls, sequential execution adds latency. This package runs them in parallel on separate DB connections in the same PHP process.

Typical use cases:

- building dashboards that need several expensive aggregate queries for different widgets;
- assembling analytics pages that combine totals, trends, leaderboards, and recent activity;
- fetching independent datasets for a single API response, such as users, transactions, invoices, and alerts;
- loading admin or reporting screens where multiple tables, counters, and summaries can be queried independently;
- reducing end-to-end latency in read-heavy endpoints that would otherwise wait on one slow query after another.

This package is most useful when queries are independent, read-heavy, and latency-bound. For tiny queries or cold-start paths, the overhead of extra connections, polling, and result collection can outweigh the benefit and even make the request slower. It is also a poor fit for strongly dependent query chains or workloads that require a shared transaction.

## High-level design

### Core components

- `ParallelDatabaseManager` - Laravel-facing entrypoint, accepts mixed query inputs.
- `QueryCompiler` - compiles builder payloads into `CompiledQuery`.
- `ParallelExecutor` - bounded scheduler + timeout + result collection.
- `DriverRegistry` - maps DB driver names to async implementations.
- `PostgresAsyncDriver`, `MySqlAsyncDriver` - native async transport.
- `ConnectionConfigResolver` + `*ConnectionFactory` - per-query dedicated connection creation.
- `ConnectionPool` - optional process-local reuse of idle async connections between runs.

### Execution flow

1. User passes map of queries (`Builder`, `Eloquent`, or `Closure` returning one of them).
2. Compiler normalizes all inputs to `CompiledQuery`.
3. Executor keeps pending/running sets and starts up to `maxConcurrency` queries on dedicated connections.
4. Drivers poll readiness (`stream_select()` for PostgreSQL sockets, `mysqli_poll()` for MySQL links).
5. Ready queries are reaped and transformed into `QueryResult`.
6. Completed connections are returned to the pool when pooling is enabled.
7. Executor continues until all queries are finished or timeout is reached.

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
    'pool' => [
        'enabled' => true,
        'max_idle_per_key' => 3,
    ],

    'drivers' => [
        'pgsql' => ['enabled' => true],
        'mysql' => ['enabled' => true],
    ],
];
```

- `default_max_concurrency` - default per-run concurrency limit used by `DB::parallel()`.
- `default_timeout_ms` - default batch timeout in milliseconds for the whole parallel run.
- `error_mode` - whether to stop on the first failure (`fail_fast`) or return failed entries alongside successful ones (`collect`).
- `pool.enabled` - enables process-local reuse of idle async connections between runs.
- `pool.max_idle_per_key` - caps how many idle pooled connections are retained per connection config.
- `drivers.pgsql.enabled` - enables the PostgreSQL async driver.
- `drivers.mysql.enabled` - enables the MySQL async driver.

## Usage

### Default DB manager path

```php
$result = DB::parallel()->run([
    'users' => DB::table('users')->where('active', true),
    'servers' => DB::table('servers')->where('status', 'ok'),
]);
```

### Accessing collections and models

```php
$result = DB::parallel()->run([
    'users' => User::query()->where('active', true),
    'transactions' => DB::table('transactions')->latest()->limit(10),
]);

$users = $result['users']->toEloquentCollection(User::class);
$transactions = $result['transactions']->toCollection();
```

### Using closures

```php
$status = 'paid';
$from = now()->subDays(30);
$limit = 10;

$result = DB::parallel()->run([
    'top_users' => fn () => User::query()
        ->select('id', 'name')
        ->withCount('transactions')
        ->whereHas('transactions', fn ($query) => $query->where('status', $status))
        ->orderByDesc('transactions_count')
        ->limit($limit),
    'recent_revenue' => function () use ($status, $from) {
        return DB::table('transactions')
            ->selectRaw('date(created_at) as day, sum(amount) as total')
            ->where('status', $status)
            ->where('created_at', '>=', $from)
            ->groupByRaw('date(created_at)')
            ->orderBy('day');
    },
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
    'users' => User::query()->where('active', true),
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

## Connection pooling

Idle async connections are pooled by default and reused across `run()` calls inside the same PHP process.

Connection pooling is especially useful in long-running environments such as Laravel Octane, where worker processes can reuse async connections across many requests. Keep in mind that the pool is process-local: each worker may retain up to `max_idle_per_key` idle connections per connection config, so total open connections should be sized with your worker count and database limits in mind.

For long-running workers and higher connection counts, consider a database-side pooler such as PgBouncer for PostgreSQL or ProxySQL for MySQL.

- pool key is based on driver/config;
- one pooled connection can still serve only one active query at a time;
- hot runs should show lower connection overhead;
- this is process-local reuse, not a shared external pool.
- `maxConcurrency` limits concurrently running queries per `run()`;
- `max_idle_per_key` limits how many idle pooled connections are retained for one connection config;
- if pooling is enabled, a long-lived process can keep more open connections than `maxConcurrency`, because completed connections may stay idle in the pool for reuse.

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
- The MySQL async path does not use prepared statements and interpolates bindings with `mysqli::real_escape_string`, which is a transport-level limitation of this approach.
- Bindings are interpolated with `mysqli::real_escape_string` and strict typing rules.

## Limitations and controversial points

1. Async API parity differs between `ext-pgsql` and `ext-mysqli`.
2. The MySQL async path does not use prepared statements; bindings are interpolated with escaping as a transport-level limitation of this approach.
3. `stream_select()` has platform limits and FD-scaling caveats for very large sets.
4. Core results are returned as raw rows, but `QueryResult` provides explicit `toCollection()` and `toEloquentCollection()` helpers for post-processing.
5. Parallel writes are independent operations, not an atomic group transaction.

## Benchmark

Synthetic PostgreSQL benchmark setup:

- 7 independent queries per run;
- 5 queries with configurable `pg_sleep(...)` delay;
- 2 regular `SELECT` queries;
- 2 warmup runs, then 10 measured iterations;
- sequential and parallel paths consume the same result sets.

This benchmark is intentionally synthetic and latency-bound. It is useful for showing when overlapping independent query latency helps, but it should not be read as a universal production speedup claim. Real-world gains depend on query cost, result size, connection overhead, database load, pooling behavior, and `maxConcurrency`.

Pool settings also affect warm-run results. With the default config, up to `3` idle connections per connection key are retained between runs.

Observed trends from the local test environment:

- higher `maxConcurrency` is not always better; the best value depends on the workload;
- longer per-query latency increased the benefit of parallel execution;
- for tiny queries, connection setup, polling, and result collection overhead could outweigh the gains.

Suggested benchmark matrix for comparing different latency profiles and scheduler settings:

| sleep_ms | maxConcurrency | sequential avg ms | parallel avg ms | speedup x | Notes |
| --- | --- | ---: | ---: | ---: | --- |
| 50 | 1 | 310.82 | 302.87 | 1.03 | Near-sequential control case, as expected |
| 50 | 2 | 313.10 | 181.11 | 1.73 | Noticeable gain with limited parallel fan-out |
| 50 | 3 | 309.33 | 124.23 | 2.49 | Best result so far for this workload on the test environment |
| 50 | 5 | 296.44 | 141.14 | 2.10 | Faster than sequential, but slower than `maxConcurrency=3` here |
| 50 | 7 | 293.76 | 176.83 | 1.66 | Higher fan-out regressed on this workload and environment |
| 20 | 3 | 158.60 | 63.87 | 2.48 | Lower-latency workload still benefits strongly at the same sweet spot |
| 20 | 5 | 135.65 | 100.63 | 1.35 | Extra fan-out helps less when per-query latency is shorter |
| 100 | 3 | 571.07 | 225.66 | 2.53 | Strong gain on a more latency-bound workload |
| 100 | 5 | 560.07 | 183.81 | 3.05 | Best result so far; higher fan-out paid off for longer waits |
| 0 | 5 | 13.74 | 59.84 | 0.23 | Tiny queries: connection, polling, and collection overhead dominated |

Cold-start overhead looks different and should be measured separately. In the following run, pooling was disabled and `warmup=0`, so the async path had to pay full connection setup cost. For this kind of cold tiny-query scenario, `p50` is more representative than `avg`, because a single startup outlier can skew the sequential mean.

| sleep_ms | maxConcurrency | pool | warmup | sequential p50 ms | parallel p50 ms | speedup x | Notes |
| --- | --- | --- | --- | ---: | ---: | ---: | --- |
| 0 | 3 | off | 0 | 13.78 | 151.27 | 0.09 | Cold tiny-query path was dominated by connection setup and async transport overhead |

Cold-start threshold sweep for the same environment (`pool=off`, `warmup=0`, `maxConcurrency=3`):

| sleep_ms | sequential p50 ms | parallel p50 ms | Notes |
| --- | ---: | ---: | --- |
| 0 | 13.78 | 151.27 | Parallel overhead dominated |
| 5 | 47.58 | 168.23 | Parallel overhead still dominated |
| 20 | 132.91 | 200.12 | Parallel was still slower on the cold path |
| 50 | 286.02 | 257.41 | Cold-start overhead started to pay off |
| 100 | 565.02 | 376.12 | Strong cold-path win on a latency-bound workload |

Takeaway from these synthetic benchmarks:

- warm runs benefited earlier, because pooled connections reduced setup overhead;
- cold runs with `pool=off` showed that parallel execution was not worthwhile for tiny queries;
- in this environment, the cold-path break-even point appeared somewhere between `20ms` and `50ms` of per-query latency;
- the optimal `maxConcurrency` depended on the workload: around `3` for shorter waits and higher for more latency-bound queries.

## Testing

Unit tests cover:

- query compilation from Builder/Eloquent/Closure;
- bounded concurrency;
- timeout handling;
- fail-fast and collect-errors behavior;
- key-preserving result mapping.

Run:

```bash
composer test
```

Integration tests with real DB engines are intentionally opt-in (`tests/Integration`).
