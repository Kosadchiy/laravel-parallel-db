<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Support;

use Kosadchiy\LaravelParallelDb\Connection\ConnectionFactoryInterface;

final class FakeConnectionFactory implements ConnectionFactoryInterface
{
    public int $created = 0;
    public int $closed = 0;

    public function create(array $config): mixed
    {
        $this->created++;

        return (object) ['id' => $this->created, 'config' => $config];
    }

    public function close(mixed $connection): void
    {
        $this->closed++;
    }
}
