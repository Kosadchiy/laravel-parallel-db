<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Connection;

final class ConnectionPool
{
    /**
     * @var array<string, list<mixed>>
     */
    private array $idle = [];

    /**
     * @var array<int|string, string>
     */
    private array $connectionKeys = [];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly int $maxIdlePerKey = 3,
    ) {
    }

    public function acquire(string $key): mixed
    {
        if (!$this->enabled) {
            return null;
        }

        if (!isset($this->idle[$key]) || $this->idle[$key] === []) {
            return null;
        }

        $connection = array_pop($this->idle[$key]);

        if ($connection === null) {
            return null;
        }

        $this->connectionKeys[$this->connectionId($connection)] = $key;

        return $connection;
    }

    public function remember(mixed $connection, string $key): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->connectionKeys[$this->connectionId($connection)] = $key;
    }

    public function release(mixed $connection): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $id = $this->connectionId($connection);
        $key = $this->connectionKeys[$id] ?? null;

        if ($key === null) {
            return false;
        }

        if (count($this->idle[$key] ?? []) >= $this->maxIdlePerKey) {
            unset($this->connectionKeys[$id]);

            return false;
        }

        $this->idle[$key] ??= [];
        $this->idle[$key][] = $connection;

        return true;
    }

    public function forget(mixed $connection): void
    {
        unset($this->connectionKeys[$this->connectionId($connection)]);
    }

    private function connectionId(mixed $connection): int|string
    {
        if (is_object($connection)) {
            return spl_object_id($connection);
        }

        if (is_resource($connection)) {
            return get_resource_id($connection);
        }

        return md5(serialize($connection));
    }
}
