<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

final readonly class RunningQuery
{
    /**
     * @param CompiledQuery $query
     * @param string $driver
     * @param mixed $connectionHandle
     * @param resource|object|null $socket
     * @param float $startedAt
     */
    public function __construct(
        public CompiledQuery $query,
        public string $driver,
        public mixed $connectionHandle,
        public mixed $socket,
        public float $startedAt,
    ) {
    }
}
