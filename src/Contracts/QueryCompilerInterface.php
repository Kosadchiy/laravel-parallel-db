<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Contracts;

use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;

interface QueryCompilerInterface
{
    /**
     * @param array<string|int, mixed> $queries
     * @return array<int, CompiledQuery>
     */
    public function compileMany(array $queries, ?string $defaultConnection = null): array;
}
