<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Kosadchiy\LaravelParallelDb\ParallelDatabaseManager;

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

        $app = $this->app;
        self::assertNotNull($app);

        $result = $app->make(ParallelDatabaseManager::class)->withOptions(
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
}
