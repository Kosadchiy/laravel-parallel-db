<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

use Illuminate\Contracts\Config\Repository;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;

final readonly class ConnectionConfigResolver
{
    public function __construct(private Repository $config)
    {
    }

    public function defaultConnection(): string
    {
        $default = $this->config->get('database.default');

        if (!is_string($default) || $default === '') {
            throw new ParallelExecutionException('Cannot resolve default database connection name.');
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(string $connection): array
    {
        $config = $this->config->get("database.connections.{$connection}");

        if (!is_array($config)) {
            throw new ParallelExecutionException("Database connection [{$connection}] is not configured.");
        }

        return $config;
    }

    public function connectionDriver(string $connection): string
    {
        $config = $this->connectionConfig($connection);
        $driver = $config['driver'] ?? null;

        if (!is_string($driver) || $driver === '') {
            throw new ParallelExecutionException("Database connection [{$connection}] has no driver configured.");
        }

        return $driver;
    }
}
