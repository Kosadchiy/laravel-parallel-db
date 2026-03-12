<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\DTO\ParallelQuery;
use Kosadchiy\LaravelParallelDb\QueryCompiler;
use Kosadchiy\LaravelParallelDb\Tests\Support\TestUser;
use PHPUnit\Framework\TestCase;

final class QueryCompilerTest extends TestCase
{
    private QueryCompiler $compiler;
    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('config', new Repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
        ]));

        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'sqlite');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->database = $capsule->getDatabaseManager();
        $this->compiler = new QueryCompiler(
            database: $this->database,
            configResolver: new ConnectionConfigResolver($container->make('config')),
        );
    }

    public function testCompilesQueryBuilder(): void
    {
        $compiled = $this->compiler->compileMany([
            'users' => $this->database->table('users')->where('active', true),
        ]);

        self::assertCount(1, $compiled);
        self::assertSame('users', $compiled[0]->key);
        self::assertSame('sqlite', $compiled[0]->driver);
        self::assertSame('select', $compiled[0]->type);
        self::assertSame([true], $compiled[0]->bindings);
    }

    public function testCompilesEloquentBuilder(): void
    {
        TestUser::setConnectionResolver($this->database);

        $compiled = $this->compiler->compileMany([
            'users' => TestUser::query()->where('active', 1),
        ]);

        self::assertSame('users', $compiled[0]->key);
        self::assertSame('select', $compiled[0]->type);
        self::assertSame([1], $compiled[0]->bindings);
    }

    public function testCompilesExplicitSqlAndParallelQueryObject(): void
    {
        $compiled = $this->compiler->compileMany([
            'raw' => [
                'sql' => 'select * from users where active = ?',
                'bindings' => [1],
            ],
            'object' => new ParallelQuery(
                sql: 'update users set active = ? where id = ?',
                bindings: [0, 10],
                connection: 'sqlite',
            ),
        ]);

        self::assertSame('select', $compiled[0]->type);
        self::assertSame('update', $compiled[1]->type);
        self::assertSame('sqlite', $compiled[1]->connection);
    }
}
