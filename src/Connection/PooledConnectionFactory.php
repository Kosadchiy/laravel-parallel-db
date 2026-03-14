<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

final readonly class PooledConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(
        private ConnectionFactoryInterface $inner,
        private ConnectionPool $pool,
    ) {
    }

    public function create(array $config): mixed
    {
        $key = $this->poolKey($config);
        $connection = $this->pool->acquire($key);

        if ($connection !== null) {
            return $connection;
        }

        $connection = $this->inner->create($config);
        $this->pool->remember($connection, $key);

        return $connection;
    }

    public function close(mixed $connection): void
    {
        if ($this->pool->release($connection)) {
            return;
        }

        $this->pool->forget($connection);
        $this->inner->close($connection);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function poolKey(array $config): string
    {
        ksort($config);

        return sha1(json_encode($config, JSON_THROW_ON_ERROR));
    }
}
