<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\DTO;

final readonly class ParallelQuery
{
    /**
     * @param array<int, mixed> $bindings
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $sql,
        public array $bindings = [],
        public ?string $connection = null,
        public array $metadata = [],
    ) {
    }
}
