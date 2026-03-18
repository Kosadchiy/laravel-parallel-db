<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryFailedException;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryTimeoutException;

final class PostgresParallelTest extends IntegrationTestCase
{
    public function testParallelQueriesRunAgainstPostgres(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        DB::connection('pgsql')->table('parallel_test_users')->insert([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        self::assertTrue(DatabaseManager::hasMacro('parallel'));
        self::assertTrue(Connection::hasMacro('parallel'));

        $result = $this->parallelManager('pgsql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            connection: 'pgsql',
        )->run([
            'users' => DB::connection('pgsql')->table('parallel_test_users')->orderBy('id'),
            'count' => DB::connection('pgsql')->table('parallel_test_users')->selectRaw('count(*) as aggregate'),
        ]);

        self::assertCount(2, $result['users']->rows);
        self::assertSame('pgsql', $result['users']->connectionDriver);
        self::assertTrue($result['users']->success);
        self::assertSame('Alice', $result['users']->rows[0]['name']);
        self::assertSame(2, (int) $result['count']->rows[0]['aggregate']);
    }

    public function testCollectModeReturnsFailedQueryResultForInvalidPostgresQuery(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        DB::connection('pgsql')->table('parallel_test_users')->insert([
            ['name' => 'Alice'],
        ]);

        $result = $this->parallelManager('pgsql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            errorMode: ErrorMode::COLLECT,
            connection: 'pgsql',
        )->run([
            'users' => DB::connection('pgsql')->table('parallel_test_users')->orderBy('id'),
            'broken' => DB::connection('pgsql')->table('missing_parallel_test_users')->orderBy('id'),
        ]);

        self::assertTrue($result['users']->success);
        self::assertFalse($result['broken']->success);
        self::assertNotNull($result['broken']->error);
        self::assertNotSame('', $result['broken']->error);
    }

    public function testFailFastThrowsForInvalidPostgresQuery(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        $this->expectException(ParallelQueryFailedException::class);

        $this->parallelManager('pgsql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            errorMode: ErrorMode::FAIL_FAST,
            connection: 'pgsql',
        )->run([
            'users' => DB::connection('pgsql')->table('parallel_test_users')->orderBy('id'),
            'broken' => DB::connection('pgsql')->table('missing_parallel_test_users')->orderBy('id'),
        ]);
    }

    public function testTimeoutThrowsForLongRunningPostgresQuery(): void
    {
        $this->requireConnection('pgsql');

        $this->expectException(ParallelQueryTimeoutException::class);

        $this->parallelManager('pgsql')->withOptions(
            timeoutMs: 50,
            connection: 'pgsql',
        )->run([
            'slow' => DB::connection('pgsql')->query()->selectRaw('pg_sleep(1) as slept'),
        ]);
    }
}
