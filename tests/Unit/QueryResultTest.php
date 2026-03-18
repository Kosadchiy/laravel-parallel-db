<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Kosadchiy\LaravelParallelDb\DTO\QueryResult;
use Kosadchiy\LaravelParallelDb\Tests\Support\TestUser;
use PHPUnit\Framework\TestCase;

final class QueryResultTest extends TestCase
{
    public function testToCollectionReturnsIlluminateCollection(): void
    {
        $result = new QueryResult(
            sql: 'select * from users',
            bindings: [],
            type: 'select',
            rows: [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            rowCount: 2,
            lastInsertId: null,
            durationMs: 1.0,
            connectionDriver: 'pgsql',
            success: true,
        );

        $collection = $result->toCollection();
        $firstRow = $collection->first();

        self::assertInstanceOf(Collection::class, $collection);
        self::assertCount(2, $collection);
        self::assertIsArray($firstRow);
        self::assertArrayHasKey('name', $firstRow);
        self::assertSame('Alice', $firstRow['name']);
    }

    public function testToEloquentCollectionHydratesModels(): void
    {
        $result = new QueryResult(
            sql: 'select * from users',
            bindings: [],
            type: 'select',
            rows: [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            rowCount: 2,
            lastInsertId: null,
            durationMs: 1.0,
            connectionDriver: 'pgsql',
            success: true,
        );

        /** @var EloquentCollection<int, TestUser> $collection */
        $collection = $result->toEloquentCollection(TestUser::class);

        self::assertInstanceOf(EloquentCollection::class, $collection);
        self::assertCount(2, $collection);
        self::assertInstanceOf(TestUser::class, $collection->first());
        self::assertSame('Alice', $collection->first()->name);
    }

    public function testToEloquentCollectionRejectsNonModelClass(): void
    {
        $result = new QueryResult(
            sql: 'select 1',
            bindings: [],
            type: 'select',
            rows: [],
            rowCount: 0,
            lastInsertId: null,
            durationMs: 1.0,
            connectionDriver: 'pgsql',
            success: true,
        );

        $this->expectException(\InvalidArgumentException::class);

        /** @var string $invalidModelClass */
        $invalidModelClass = Collection::class;

        // Runtime validation intentionally accepts arbitrary strings and rejects non-model classes.
        /** @phpstan-ignore-next-line */
        $result->toEloquentCollection($invalidModelClass);
    }
}
