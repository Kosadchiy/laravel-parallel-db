<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

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
