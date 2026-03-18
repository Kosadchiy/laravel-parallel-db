<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Kosadchiy\LaravelParallelDb\Tests\Support\ParallelTestUser;

final class ParallelQueryShapeTest extends IntegrationTestCase
{
    public function testPostgresRunSupportsQueryBuilderEloquentBuilderAndClosure(): void
    {
        $this->requireConnection('pgsql');
        $this->recreateUsersTable('pgsql');

        DB::connection('pgsql')->table('parallel_test_users')->insert([
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Carol', 'active' => true],
        ]);

        $result = $this->parallelManager('pgsql')->run([
            'builder' => DB::connection('pgsql')->table('parallel_test_users')
                ->select('id', 'name')
                ->where('active', true)
                ->orderBy('id'),
            'eloquent' => ParallelTestUser::on('pgsql')
                ->where('active', true)
                ->orderBy('id'),
            'closure' => fn () => DB::connection('pgsql')->table('parallel_test_users')
                ->selectRaw('count(*) as aggregate')
                ->where('active', true),
        ]);

        self::assertCount(2, $result['builder']->rows);
        self::assertSame('Alice', $result['builder']->rows[0]['name']);
        self::assertCount(2, $result['eloquent']->toEloquentCollection(ParallelTestUser::class));
        self::assertSame(2, (int) $result['closure']->rows[0]['aggregate']);
    }

    public function testMysqlRunSupportsQueryBuilderEloquentBuilderAndClosure(): void
    {
        $this->requireConnection('mysql');
        $this->recreateUsersTable('mysql');

        DB::connection('mysql')->table('parallel_test_users')->insert([
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
            ['name' => 'Carol', 'active' => true],
        ]);

        $result = $this->parallelManager('mysql')->run([
            'builder' => DB::connection('mysql')->table('parallel_test_users')
                ->select('id', 'name')
                ->where('active', true)
                ->orderBy('id'),
            'eloquent' => ParallelTestUser::on('mysql')
                ->where('active', true)
                ->orderBy('id'),
            'closure' => fn () => DB::connection('mysql')->table('parallel_test_users')
                ->selectRaw('count(*) as aggregate')
                ->where('active', true),
        ]);

        self::assertCount(2, $result['builder']->rows);
        self::assertSame('Alice', $result['builder']->rows[0]['name']);
        self::assertCount(2, $result['eloquent']->toEloquentCollection(ParallelTestUser::class));
        self::assertSame(2, (int) $result['closure']->rows[0]['aggregate']);
    }
}
