<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

final readonly class CompiledQuery
{
    /**
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public string $key,
        public string $sql,
        public array $bindings,
        public string $type,
        public string $connection,
        public string $driver,
    ) {
    }
}
