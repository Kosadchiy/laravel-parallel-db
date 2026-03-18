<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

use Kosadchiy\LaravelParallelDb\Enum\QueryType;

final readonly class CompiledQuery
{
    /**
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public string $key,
        public string $sql,
        public array $bindings,
        public QueryType $type,
        public string $connection,
        public string $driver,
    ) {
    }
}
