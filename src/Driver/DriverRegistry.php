<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Driver;

use Kosadchiy\LaravelParallelDb\Exceptions\DriverNotSupportedException;

final class DriverRegistry
{
    /**
     * @var array<string, AsyncDriverInterface>
     */
    private array $drivers = [];

    /**
     * @param iterable<int, AsyncDriverInterface> $drivers
     */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(AsyncDriverInterface $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function get(string $driver): AsyncDriverInterface
    {
        if (!isset($this->drivers[$driver])) {
            throw new DriverNotSupportedException("Async database driver [{$driver}] is not supported.");
        }

        return $this->drivers[$driver];
    }

    /**
     * @return array<string, AsyncDriverInterface>
     */
    public function all(): array
    {
        return $this->drivers;
    }
}
