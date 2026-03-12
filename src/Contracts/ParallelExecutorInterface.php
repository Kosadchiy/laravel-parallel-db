<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Contracts;

use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;

interface ParallelExecutorInterface
{
    /**
     * @param array<int, CompiledQuery> $queries
     * @return array<string, QueryResult>
     */
    public function execute(array $queries, ParallelOptions $options): array;
}
