<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Kosadchiy\LaravelParallelDb\Enum\QueryType;

final class ParallelWriteQueryTest extends IntegrationTestCase
{
    public function testPostgresExecutorRunsInsertUpdateAndDeleteQueries(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        $results = $this->parallelExecutor()->execute([
            $this->compiledQuery('insert', 'insert into parallel_test_users (name, active) values (?, ?)', ['Alice', true], QueryType::INSERT, 'pgsql'),
            $this->compiledQuery('insert_second', 'insert into parallel_test_users (name, active) values (?, ?)', ['Bob', false], QueryType::INSERT, 'pgsql'),
        ], $this->defaultParallelOptions('pgsql'));

        self::assertSame(1, $results['insert']->rowCount);
        self::assertSame(1, $results['insert_second']->rowCount);

        $mutations = $this->parallelExecutor()->execute([
            $this->compiledQuery('update', 'update parallel_test_users set active = ? where name = ?', [false, 'Alice'], QueryType::UPDATE, 'pgsql'),
            $this->compiledQuery('delete', 'delete from parallel_test_users where name = ?', ['Bob'], QueryType::DELETE, 'pgsql'),
        ], $this->defaultParallelOptions('pgsql'));

        self::assertSame(1, $mutations['update']->rowCount);
        self::assertSame(1, $mutations['delete']->rowCount);
        self::assertFalse((bool) DB::connection('pgsql')->table('parallel_test_users')->where('name', 'Alice')->value('active'));
        self::assertSame(1, DB::connection('pgsql')->table('parallel_test_users')->count());
    }

    public function testMysqlExecutorRunsInsertUpdateAndDeleteQueries(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        $results = $this->parallelExecutor()->execute([
            $this->compiledQuery('insert', 'insert into parallel_test_users (name, active) values (?, ?)', ['Alice', true], QueryType::INSERT, 'mysql'),
            $this->compiledQuery('insert_second', 'insert into parallel_test_users (name, active) values (?, ?)', ['Bob', false], QueryType::INSERT, 'mysql'),
        ], $this->defaultParallelOptions('mysql'));

        self::assertSame(1, $results['insert']->rowCount);
        self::assertSame(1, $results['insert_second']->rowCount);
        self::assertNotNull($results['insert']->lastInsertId);

        $mutations = $this->parallelExecutor()->execute([
            $this->compiledQuery('update', 'update parallel_test_users set active = ? where name = ?', [false, 'Alice'], QueryType::UPDATE, 'mysql'),
            $this->compiledQuery('delete', 'delete from parallel_test_users where name = ?', ['Bob'], QueryType::DELETE, 'mysql'),
        ], $this->defaultParallelOptions('mysql'));

        self::assertSame(1, $mutations['update']->rowCount);
        self::assertSame(1, $mutations['delete']->rowCount);
        self::assertFalse((bool) DB::connection('mysql')->table('parallel_test_users')->where('name', 'Alice')->value('active'));
        self::assertSame(1, DB::connection('mysql')->table('parallel_test_users')->count());
    }

    public function testPostgresParallelConnectionsDoNotSeeUncommittedTransactionData(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        DB::connection('pgsql')->beginTransaction();

        try {
            DB::connection('pgsql')->table('parallel_test_users')->insert([
                'name' => 'Invisible Alice',
                'active' => true,
            ]);

            $results = $this->parallelExecutor()->execute([
                $this->compiledQuery('count', 'select count(*) as aggregate from parallel_test_users', [], QueryType::SELECT, 'pgsql'),
            ], $this->defaultParallelOptions('pgsql'));

            self::assertSame(0, (int) $results['count']->rows[0]['aggregate']);
        } finally {
            DB::connection('pgsql')->rollBack();
        }
    }

    public function testMysqlParallelConnectionsDoNotSeeUncommittedTransactionData(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        DB::connection('mysql')->beginTransaction();

        try {
            DB::connection('mysql')->table('parallel_test_users')->insert([
                'name' => 'Invisible Alice',
                'active' => true,
            ]);

            $results = $this->parallelExecutor()->execute([
                $this->compiledQuery('count', 'select count(*) as aggregate from parallel_test_users', [], QueryType::SELECT, 'mysql'),
            ], $this->defaultParallelOptions('mysql'));

            self::assertSame(0, (int) $results['count']->rows[0]['aggregate']);
        } finally {
            DB::connection('mysql')->rollBack();
        }
    }
}
