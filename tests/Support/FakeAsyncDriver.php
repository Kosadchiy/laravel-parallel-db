<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use Kosadchiy\LaravelParallelDb\Driver\AsyncDriverInterface;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;

final class FakeAsyncDriver implements AsyncDriverInterface
{
    public int $maxActive = 0;
    private int $active = 0;

    /**
     * @param array<string, array{duration_ms?: int, fail?: bool, error?: string}> $scenarios
     */
    public function __construct(
        private readonly string $name = 'fake',
        private readonly array $scenarios = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function start(CompiledQuery $query): RunningQuery
    {
        $this->active++;
        $this->maxActive = max($this->maxActive, $this->active);

        return new RunningQuery(
            query: $query,
            driver: $this->name,
            connectionHandle: (object) ['started' => microtime(true)],
            socket: null,
            startedAt: microtime(true),
        );
    }

    public function poll(array $runningQueries, int $timeoutMs): array
    {
        if ($timeoutMs > 0) {
            usleep($timeoutMs * 1000);
        }

        $ready = [];
        $now = microtime(true);

        foreach ($runningQueries as $key => $running) {
            $durationMs = (int) ($this->scenario($running->query->key)['duration_ms'] ?? 1);
            $elapsedMs = ($now - $running->startedAt) * 1000;

            if ($elapsedMs >= $durationMs) {
                $ready[] = $key;
            }
        }

        return $ready;
    }

    public function collect(RunningQuery $runningQuery): QueryResult
    {
        $query = $runningQuery->query;
        $durationMs = (microtime(true) - $runningQuery->startedAt) * 1000;
        $scenario = $this->scenario($query->key);

        if (($scenario['fail'] ?? false) === true) {
            return QueryResult::failure(
                sql: $query->sql,
                bindings: $query->bindings,
                type: $query->type,
                connectionDriver: $query->driver,
                durationMs: $durationMs,
                error: (string) ($scenario['error'] ?? 'Synthetic failure'),
            );
        }

        return new QueryResult(
            sql: $query->sql,
            bindings: $query->bindings,
            type: $query->type,
            rows: [['ok' => true]],
            rowCount: 1,
            lastInsertId: null,
            durationMs: $durationMs,
            connectionDriver: $query->driver,
            success: true,
            error: null,
        );
    }

    public function close(RunningQuery $runningQuery): void
    {
        $this->active = max(0, $this->active - 1);
    }

    /**
     * @return array{duration_ms?: int, fail?: bool, error?: string}
     */
    private function scenario(string $key): array
    {
        return $this->scenarios[$key] ?? [];
    }
}
