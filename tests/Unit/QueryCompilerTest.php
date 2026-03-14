<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\QueryCompiler;
use Kosadchiy\LaravelParallelDb\Tests\Support\ArrayConfigRepository;
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
        $container->instance('config', new ArrayConfigRepository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => [
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
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->database = $capsule->getDatabaseManager();
        $this->compiler = new QueryCompiler(
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
        self::assertSame([1], $compiled[0]->bindings);
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

    public function testCompilesClosureReturningBuilder(): void
    {
        $compiled = $this->compiler->compileMany([
            'users' => fn () => $this->database->table('users')->where('active', false),
        ]);

        self::assertSame('users', $compiled[0]->key);
        self::assertSame('select', $compiled[0]->type);
        self::assertSame([0], $compiled[0]->bindings);
    }

    public function testCompilesClosureReturningEloquentBuilder(): void
    {
        TestUser::setConnectionResolver($this->database);

        $compiled = $this->compiler->compileMany([
            'users' => fn () => TestUser::query()->where('active', 0),
        ]);

        self::assertSame('users', $compiled[0]->key);
        self::assertSame('select', $compiled[0]->type);
        self::assertSame([0], $compiled[0]->bindings);
    }
}
