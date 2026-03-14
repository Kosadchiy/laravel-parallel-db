<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Kosadchiy\LaravelParallelDb\Connection\ConnectionPool;
use Kosadchiy\LaravelParallelDb\Connection\PooledConnectionFactory;
use Kosadchiy\LaravelParallelDb\Tests\Support\FakeConnectionFactory;
use PHPUnit\Framework\TestCase;

final class PooledConnectionFactoryTest extends TestCase
{
    public function testReusesIdleConnectionForSameConfig(): void
    {
        $inner = new FakeConnectionFactory();
        $factory = new PooledConnectionFactory($inner, new ConnectionPool(enabled: true, maxIdlePerKey: 2));
        $config = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'test'];

        $first = $factory->create($config);
        $factory->close($first);
        $second = $factory->create($config);

        self::assertSame($first, $second);
        self::assertSame(1, $inner->created);
        self::assertSame(0, $inner->closed);
    }

    public function testDoesNotReuseWhenPoolDisabled(): void
    {
        $inner = new FakeConnectionFactory();
        $factory = new PooledConnectionFactory($inner, new ConnectionPool(enabled: false, maxIdlePerKey: 2));
        $config = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'test'];

        $first = $factory->create($config);
        $factory->close($first);
        $second = $factory->create($config);

        self::assertNotSame($first, $second);
        self::assertSame(2, $inner->created);
        self::assertSame(1, $inner->closed);
    }
}
