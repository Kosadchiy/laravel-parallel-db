<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;

final class PostgresPlaceholderConverter
{
    /**
     * Converts SQL placeholders from ? to $1..$N for pg_send_query_params.
     *
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    public static function questionMarksToPgParams(string $sql, array $bindings): array
    {
        return [
            'sql' => self::convertPlaceholders($sql, $bindings),
            'bindings' => $bindings,
        ];
    }

    /**
     * Replaces only real placeholders and ignores question marks inside
     * quoted strings, identifiers, comments, and PostgreSQL json operators.
     *
     * @param array<int, mixed> $bindings
     */
    private static function convertPlaceholders(string $sql, array $bindings): string
    {
        $bindingIndex = 0;
        $result = SqlPlaceholderParser::transform(
            $sql,
            static function (int $_placeholderIndex) use (&$bindingIndex, $bindings): string {
                if (!array_key_exists($bindingIndex, $bindings)) {
                    throw new ParallelExecutionException(sprintf(
                        'Bindings count mismatch for PostgreSQL async query: placeholders exceed bindings at index %d',
                        $bindingIndex,
                    ));
                }

                $bindingIndex++;

                return '$' . $bindingIndex;
            },
            static fn (string $currentSql, int $index, ?string $next): bool => self::isPgOperatorQuestionMark($currentSql, $index, $next),
            true,
        );

        if ($bindingIndex !== count($bindings)) {
            throw new ParallelExecutionException(sprintf(
                'Bindings count mismatch for PostgreSQL async query: placeholders=%d bindings=%d',
                $bindingIndex,
                count($bindings),
            ));
        }

        return $result;
    }

    private static function isPgOperatorQuestionMark(string $sql, int $index, ?string $next): bool
    {
        if ($next === '|' || $next === '&') {
            return true;
        }

        $previous = self::previousSignificantChar($sql, $index);
        $following = self::nextSignificantChar($sql, $index);

        if ($previous === null || $following === null) {
            return false;
        }

        return self::isOperatorLeftOperand($previous) && self::isOperatorRightOperand($following);
    }

    private static function previousSignificantChar(string $sql, int $index): ?string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!ctype_space($sql[$i])) {
                return $sql[$i];
            }
        }

        return null;
    }

    private static function nextSignificantChar(string $sql, int $index): ?string
    {
        $length = strlen($sql);

        for ($i = $index + 1; $i < $length; $i++) {
            if (!ctype_space($sql[$i])) {
                return $sql[$i];
            }
        }

        return null;
    }

    private static function isOperatorLeftOperand(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === ')' || $char === ']' || $char === '"' || $char === '\'';
    }

    private static function isOperatorRightOperand(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '(' || $char === '[' || $char === '"' || $char === '\'' || $char === '$';
    }

}
