<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

final class RunningQuery
{
    /**
     * @param mixed $connectionHandle
     * @param resource|object|null $socket
     */
    public function __construct(
        public readonly CompiledQuery $query,
        public readonly string $driver,
        public readonly mixed $connectionHandle,
        public readonly mixed $socket,
        public readonly float $startedAt,
    ) {
    }
}
