<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

use Kosadchiy\LaravelParallelDb\Enum\QueryType;

final class SqlTypeDetector
{
    public static function detect(string $sql): QueryType
    {
        $normalized = strtolower(ltrim($sql));

        return match (true) {
            str_starts_with($normalized, 'select'), str_starts_with($normalized, 'with') => QueryType::SELECT,
            str_starts_with($normalized, 'insert') => QueryType::INSERT,
            str_starts_with($normalized, 'update') => QueryType::UPDATE,
            str_starts_with($normalized, 'delete') => QueryType::DELETE,
            str_starts_with($normalized, 'create'),
            str_starts_with($normalized, 'alter'),
            str_starts_with($normalized, 'drop'),
            str_starts_with($normalized, 'truncate') => QueryType::DDL,
            default => QueryType::OTHER,
        };
    }
}
