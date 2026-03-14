<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class QueryResult
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public string $type,
        public array $rows,
        public int $rowCount,
        public ?string $lastInsertId,
        public float $durationMs,
        public string $connectionDriver,
        public bool $success,
        public ?string $error = null,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function toCollection(): Collection
    {
        return collect($this->rows);
    }

    /**
     * @param class-string<Model> $modelClass
     * @return EloquentCollection<int, Model>
     */
    public function toEloquentCollection(string $modelClass): EloquentCollection
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected an Eloquent model class, got [%s].',
                $modelClass,
            ));
        }

        /** @var Model $modelClass */
        return $modelClass::hydrate($this->rows);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public static function failure(
        string $sql,
        array $bindings,
        string $type,
        string $connectionDriver,
        float $durationMs,
        string $error,
    ): self {
        return new self(
            sql: $sql,
            bindings: $bindings,
            type: $type,
            rows: [],
            rowCount: 0,
            lastInsertId: null,
            durationMs: $durationMs,
            connectionDriver: $connectionDriver,
            success: false,
            error: $error,
        );
    }
}
