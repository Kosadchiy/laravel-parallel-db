<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Kosadchiy\LaravelParallelDb\Enum\ErrorMode;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryFailedException;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelQueryTimeoutException;

final class MySqlParallelTest extends IntegrationTestCase
{
    public function testParallelQueriesRunAgainstMysql(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        DB::connection('mysql')->table('parallel_test_users')->insert([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);

        self::assertTrue(DatabaseManager::hasMacro('parallel'));
        self::assertTrue(Connection::hasMacro('parallel'));

        $result = $this->parallelManager('mysql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            connection: 'mysql',
        )->run([
            'users' => DB::connection('mysql')->table('parallel_test_users')->orderBy('id'),
            'count' => DB::connection('mysql')->table('parallel_test_users')->selectRaw('count(*) as aggregate'),
        ]);

        self::assertCount(2, $result['users']->rows);
        self::assertSame('mysql', $result['users']->connectionDriver);
        self::assertTrue($result['users']->success);
        self::assertSame('Alice', $result['users']->rows[0]['name']);
        self::assertSame(2, (int) $result['count']->rows[0]['aggregate']);
    }

    public function testCollectModeReturnsFailedQueryResultForInvalidMysqlQuery(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        DB::connection('mysql')->table('parallel_test_users')->insert([
            ['name' => 'Alice'],
        ]);

        $result = $this->parallelManager('mysql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            errorMode: ErrorMode::COLLECT,
            connection: 'mysql',
        )->run([
            'users' => DB::connection('mysql')->table('parallel_test_users')->orderBy('id'),
            'broken' => DB::connection('mysql')->table('missing_parallel_test_users')->orderBy('id'),
        ]);

        self::assertTrue($result['users']->success);
        self::assertFalse($result['broken']->success);
        self::assertNotNull($result['broken']->error);
        self::assertNotSame('', $result['broken']->error);
    }

    public function testFailFastThrowsForInvalidMysqlQuery(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        $this->expectException(ParallelQueryFailedException::class);

        $this->parallelManager('mysql')->withOptions(
            maxConcurrency: 2,
            timeoutMs: 2000,
            errorMode: ErrorMode::FAIL_FAST,
            connection: 'mysql',
        )->run([
            'users' => DB::connection('mysql')->table('parallel_test_users')->orderBy('id'),
            'broken' => DB::connection('mysql')->table('missing_parallel_test_users')->orderBy('id'),
        ]);
    }

    public function testTimeoutThrowsForLongRunningMysqlQuery(): void
    {
        $this->requireConnection('mysql');

        $this->expectException(ParallelQueryTimeoutException::class);

        $this->parallelManager('mysql')->withOptions(
            timeoutMs: 50,
            connection: 'mysql',
        )->run([
            'slow' => DB::connection('mysql')->query()->selectRaw('sleep(1) as slept'),
        ]);
    }
}
