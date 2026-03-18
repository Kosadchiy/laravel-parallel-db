<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Kosadchiy\LaravelParallelDb\Connection\ConnectionConfigResolver;
use Kosadchiy\LaravelParallelDb\Enum\QueryType;
use Kosadchiy\LaravelParallelDb\Exceptions\QueryCompilationException;
use Kosadchiy\LaravelParallelDb\QueryCompiler;
use Kosadchiy\LaravelParallelDb\Tests\Support\ArrayConfigRepository;
use Kosadchiy\LaravelParallelDb\Tests\Support\TestUser;
use PHPUnit\Framework\TestCase;

final class QueryCompilerAdvancedTest extends TestCase
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

    public function testCompilesComplexQueryBuilderShape(): void
    {
        $compiled = $this->compiler->compileMany([
            'active_users' => $this->database->table('users')
                ->join('profiles', 'profiles.user_id', '=', 'users.id')
                ->select('users.id', 'profiles.country')
                ->where('users.active', true)
                ->whereIn('profiles.country', ['CY', 'US'])
                ->orderBy('users.id'),
        ]);

        self::assertSame('active_users', $compiled[0]->key);
        self::assertSame(QueryType::SELECT, $compiled[0]->type);
        self::assertSame([1, 'CY', 'US'], $compiled[0]->bindings);
        self::assertStringContainsString('join "profiles"', $compiled[0]->sql);
        self::assertStringContainsString('where "users"."active" = ?', $compiled[0]->sql);
    }

    public function testCompilesClosureCapturingVariablesIntoQueryBuilder(): void
    {
        $active = true;
        $countries = ['CY', 'US'];

        $compiled = $this->compiler->compileMany([
            'users' => fn () => $this->database->table('users')
                ->select('id', 'name')
                ->where('active', $active)
                ->whereIn('country', $countries),
        ]);

        self::assertSame('users', $compiled[0]->key);
        self::assertSame([1, 'CY', 'US'], $compiled[0]->bindings);
    }

    public function testCompilesEloquentBuilderWithCustomConnectionName(): void
    {
        TestUser::setConnectionResolver($this->database);
        $builder = TestUser::on('default')->where('active', 1);

        $compiled = $this->compiler->compileMany([
            'users' => $builder,
        ]);

        self::assertSame('default', $compiled[0]->connection);
        self::assertSame('sqlite', $compiled[0]->driver);
        self::assertSame(QueryType::SELECT, $compiled[0]->type);
    }

    public function testThrowsForUnsupportedQueryPayload(): void
    {
        $this->expectException(QueryCompilationException::class);

        $this->compiler->compileMany([
            'bad' => 'select * from users',
        ]);
    }
}
