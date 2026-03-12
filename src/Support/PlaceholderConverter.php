<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;

final class PlaceholderConverter
{
    /**
     * Converts SQL placeholders from ? to $1..$N for pg_send_query_params.
     *
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    public static function questionMarksToPgParams(string $sql, array $bindings): array
    {
        $count = substr_count($sql, '?');

        if ($count !== count($bindings)) {
            throw new ParallelExecutionException(sprintf(
                'Bindings count mismatch for PostgreSQL async query: placeholders=%d bindings=%d',
                $count,
                count($bindings),
            ));
        }

        if ($count === 0) {
            return ['sql' => $sql, 'bindings' => $bindings];
        }

        $parts = explode('?', $sql);
        $resultSql = array_shift($parts);

        foreach ($parts as $index => $part) {
            $resultSql .= '$' . ($index + 1) . $part;
        }

        return ['sql' => $resultSql, 'bindings' => $bindings];
    }
}
