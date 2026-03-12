<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

use DateTimeInterface;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use mysqli;

final class MysqlBindingInterpolator
{
    /**
     * @param array<int, mixed> $bindings
     */
    public static function interpolate(mysqli $connection, string $sql, array $bindings): string
    {
        $placeholderCount = substr_count($sql, '?');

        if ($placeholderCount !== count($bindings)) {
            throw new ParallelExecutionException(sprintf(
                'Bindings count mismatch for MySQL async query: placeholders=%d bindings=%d',
                $placeholderCount,
                count($bindings),
            ));
        }

        if ($placeholderCount === 0) {
            return $sql;
        }

        $parts = explode('?', $sql);
        $interpolated = array_shift($parts);

        foreach ($parts as $index => $part) {
            $interpolated .= self::quote($connection, $bindings[$index]) . $part;
        }

        return $interpolated;
    }

    private static function quote(mysqli $connection, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => "'" . $connection->real_escape_string($value->format('Y-m-d H:i:s')) . "'",
            is_string($value) => "'" . $connection->real_escape_string($value) . "'",
            default => throw new ParallelExecutionException('Unsupported binding type for MySQL async interpolation: ' . get_debug_type($value)),
        };
    }
}
