<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Kosadchiy\LaravelParallelDb\Driver\DriverRegistry;
use Kosadchiy\LaravelParallelDb\DTO\CompiledQuery;
use Kosadchiy\LaravelParallelDb\DTO\ParallelOptions;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryFailedException;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryTimeoutException;
use Kosadchiy\LaravelParallelDb\ParallelExecutor;
use Kosadchiy\LaravelParallelDb\Tests\Support\FakeAsyncDriver;
use PHPUnit\Framework\TestCase;

final class ParallelExecutorTest extends TestCase
{
    public function testRespectsBoundedConcurrencyAndResultMapping(): void
    {
        $driver = new FakeAsyncDriver(scenarios: [
            'q1' => ['duration_ms' => 20],
            'q2' => ['duration_ms' => 20],
            'q3' => ['duration_ms' => 20],
            'q4' => ['duration_ms' => 20],
            'q5' => ['duration_ms' => 20],
            'q6' => ['duration_ms' => 20],
        ]);
        $executor = new ParallelExecutor(new DriverRegistry([$driver]));

        $queries = [];
        for ($i = 1; $i <= 6; $i++) {
            $queries[] = new CompiledQuery(
                key: 'q' . $i,
                sql: 'select ' . $i,
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            );
        }

        $results = $executor->execute($queries, new ParallelOptions(maxConcurrency: 2, timeoutMs: 2000));

        self::assertCount(6, $results);
        self::assertLessThanOrEqual(2, $driver->maxActive);
        self::assertArrayHasKey('q1', $results);
        self::assertArrayHasKey('q6', $results);
    }

    public function testThrowsOnTimeout(): void
    {
        $driver = new FakeAsyncDriver(scenarios: [
            'slow' => ['duration_ms' => 200],
        ]);
        $executor = new ParallelExecutor(new DriverRegistry([$driver]));

        $queries = [
            new CompiledQuery(
                key: 'slow',
                sql: 'select 1',
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            ),
        ];

        $this->expectException(ParallelQueryTimeoutException::class);

        $executor->execute($queries, new ParallelOptions(maxConcurrency: 1, timeoutMs: 20));
    }

    public function testFailFastThrowsOnFirstError(): void
    {
        $driver = new FakeAsyncDriver(scenarios: [
            'ok' => ['duration_ms' => 1],
            'bad' => ['duration_ms' => 1, 'fail' => true, 'error' => 'boom'],
        ]);
        $executor = new ParallelExecutor(new DriverRegistry([$driver]));

        $queries = [
            new CompiledQuery(
                key: 'ok',
                sql: 'select 1',
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            ),
            new CompiledQuery(
                key: 'bad',
                sql: 'select 2',
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            ),
        ];

        $this->expectException(ParallelQueryFailedException::class);

        $executor->execute($queries, new ParallelOptions(maxConcurrency: 2, timeoutMs: 500, errorMode: ErrorMode::FAIL_FAST));
    }

    public function testCollectModeKeepsErrorsInResults(): void
    {
        $driver = new FakeAsyncDriver(scenarios: [
            'ok' => ['duration_ms' => 1],
            'bad' => ['duration_ms' => 1, 'fail' => true, 'error' => 'boom'],
        ]);
        $executor = new ParallelExecutor(new DriverRegistry([$driver]));

        $queries = [
            new CompiledQuery(
                key: 'ok',
                sql: 'select 1',
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            ),
            new CompiledQuery(
                key: 'bad',
                sql: 'select 2',
                bindings: [],
                type: 'select',
                connection: 'fake',
                driver: 'fake',
            ),
        ];

        $results = $executor->execute($queries, new ParallelOptions(maxConcurrency: 2, timeoutMs: 500, errorMode: ErrorMode::COLLECT));

        self::assertTrue($results['ok']->success);
        self::assertFalse($results['bad']->success);
        self::assertSame('boom', $results['bad']->error);
    }
}
