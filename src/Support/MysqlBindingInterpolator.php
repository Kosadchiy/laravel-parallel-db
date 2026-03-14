<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

use BackedEnum;
use DateTimeInterface;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use mysqli;
use Stringable;

final class MysqlBindingInterpolator
{
    /**
     * @param array<int, mixed> $bindings
     * @param null|callable(string): string $escaper
     */
    public static function interpolate(mysqli $connection, string $sql, array $bindings, ?callable $escaper = null): string
    {
        if ($bindings === []) {
            return $sql;
        }

        $escape = $escaper ?? static fn (string $value): string => $connection->real_escape_string($value);

        return self::interpolatePlaceholders($sql, $bindings, $escape);
    }

    /**
     * @param callable(string): string $escaper
     */
    private static function quote(mixed $value, callable $escaper): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::quoteFloat($value),
            $value instanceof DateTimeInterface => "'" . $escaper(self::formatDateTime($value)) . "'",
            is_string($value) => "'" . $escaper($value) . "'",
            default => throw new ParallelExecutionException('Unsupported binding type for MySQL async interpolation: ' . get_debug_type($value)),
        };
    }

    /**
     * Replaces only real placeholders and ignores question marks inside
     * quoted strings, identifiers, and SQL comments.
     *
     * @param array<int, mixed> $bindings
     * @param callable(string): string $escaper
     */
    private static function interpolatePlaceholders(string $sql, array $bindings, callable $escaper): string
    {
        $bindingIndex = 0;
        $result = SqlPlaceholderParser::transform(
            $sql,
            static function (int $_placeholderIndex) use (&$bindingIndex, $bindings, $escaper): string {
                if (!array_key_exists($bindingIndex, $bindings)) {
                    throw new ParallelExecutionException(sprintf(
                        'Bindings count mismatch for MySQL async query: placeholders exceed bindings at index %d',
                        $bindingIndex,
                    ));
                }

                $replacement = self::quote($bindings[$bindingIndex], $escaper);
                $bindingIndex++;

                return $replacement;
            },
        );

        if ($bindingIndex !== count($bindings)) {
            throw new ParallelExecutionException(sprintf(
                'Bindings count mismatch for MySQL async query: placeholders=%d bindings=%d',
                $bindingIndex,
                count($bindings),
            ));
        }

        return $result;
    }

    private static function quoteFloat(float $value): string
    {
        if (!is_finite($value)) {
            throw new ParallelExecutionException('Non-finite floats are not supported for MySQL async interpolation.');
        }

        $normalized = sprintf('%.14F', $value);
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        if ($normalized === '-0') {
            return '0';
        }

        return $normalized === '' ? '0' : $normalized;
    }

    private static function formatDateTime(DateTimeInterface $value): string
    {
        $microseconds = $value->format('u');

        if ($microseconds !== '000000') {
            return $value->format('Y-m-d H:i:s.u');
        }

        return $value->format('Y-m-d H:i:s');
    }
}
