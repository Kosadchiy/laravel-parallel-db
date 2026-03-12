<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Driver;

use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\DTO\RunningQuery;

interface AsyncDriverInterface
{
    public function name(): string;

    public function start(CompiledQuery $query): RunningQuery;

    /**
     * @param array<string, RunningQuery> $runningQueries
     * @return array<int, string> Keys from $runningQueries that are ready.
     */
    public function poll(array $runningQueries, int $timeoutMs): array;

    public function collect(RunningQuery $runningQuery): QueryResult;

    public function close(RunningQuery $runningQuery): void;
}
