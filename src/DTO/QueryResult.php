<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kosadchiy\LaravelParallelDb\Enum\QueryType;

final readonly class QueryResult
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public QueryType $type,
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
     * @template TModel of Model
     * @param class-string<TModel> $modelClass
     * @return EloquentCollection<int, TModel>
     */
    public function toEloquentCollection(string $modelClass): EloquentCollection
    {
        if (!is_a($modelClass, Model::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Expected an Eloquent model class, got [%s].',
                $modelClass,
            ));
        }

        $model = new $modelClass();
        $items = [];

        foreach ($this->rows as $row) {
            /** @var TModel $hydrated */
            $hydrated = $model->newFromBuilder($row);
            $items[] = $hydrated;
        }

        /** @var EloquentCollection<int, TModel> $collection */
        $collection = $model->newCollection($items);

        return $collection;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public static function failure(
        string $sql,
        array $bindings,
        QueryType $type,
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
