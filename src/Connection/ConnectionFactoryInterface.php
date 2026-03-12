<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

interface ConnectionFactoryInterface
{
    /**
     * @param array<string, mixed> $config
     * @return mixed
     */
    public function create(array $config): mixed;

    public function close(mixed $connection): void;
}
