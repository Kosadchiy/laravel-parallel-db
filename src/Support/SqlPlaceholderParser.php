<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Support;

final class SqlPlaceholderParser
{
    /**
     * Walks SQL and replaces only real placeholders, ignoring quoted strings,
     * identifiers, comments, and optionally PostgreSQL dollar-quoted strings.
     *
     * @param callable(int): string $onPlaceholder
     * @param null|callable(string, int, null|string): bool $shouldKeepQuestionMark
     */
    public static function transform(
        string $sql,
        callable $onPlaceholder,
        ?callable $shouldKeepQuestionMark = null,
        bool $supportDollarQuotes = false,
    ): string {
        $result = '';
        $length = strlen($sql);
        $placeholderIndex = 0;
        $state = 'normal';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : null;

            if ($state === 'normal') {
                if ($char === "'" || $char === '"' || $char === '`') {
                    $state = $char;
                    $result .= $char;
                    continue;
                }

                if ($char === '-' && $next === '-') {
                    $state = 'line_comment';
                    $result .= $char . $next;
                    $i++;
                    continue;
                }

                if ($char === '#') {
                    $state = 'line_comment';
                    $result .= $char;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $state = 'block_comment';
                    $result .= $char . $next;
                    $i++;
                    continue;
                }

                $dollarQuote = $supportDollarQuotes && $char === '$' ? self::dollarQuoteDelimiter($sql, $i) : null;
                if ($dollarQuote !== null) {
                    $result .= $dollarQuote;
                    $i += strlen($dollarQuote) - 1;
                    $state = $dollarQuote;
                    continue;
                }

                if ($char === '?') {
                    if ($shouldKeepQuestionMark !== null && $shouldKeepQuestionMark($sql, $i, $next)) {
                        $result .= $char;
                        continue;
                    }

                    $result .= $onPlaceholder($placeholderIndex);
                    $placeholderIndex++;
                    continue;
                }

                $result .= $char;
                continue;
            }

            if ($state === 'line_comment') {
                $result .= $char;

                if ($char === "\n") {
                    $state = 'normal';
                }

                continue;
            }

            if ($state === 'block_comment') {
                $result .= $char;

                if ($char === '*' && $next === '/') {
                    $result .= $next;
                    $i++;
                    $state = 'normal';
                }

                continue;
            }

            if ($state[0] === '$') {
                if (substr($sql, $i, strlen($state)) === $state) {
                    $result .= $state;
                    $i += strlen($state) - 1;
                    $state = 'normal';
                    continue;
                }

                $result .= $char;
                continue;
            }

            $result .= $char;

            if ($char === '\\' && ($state === "'" || $state === '"') && $next !== null) {
                $result .= $next;
                $i++;
                continue;
            }

            if ($char === $state) {
                if (($state === "'" || $state === '"') && $next === $state) {
                    $result .= $next;
                    $i++;
                    continue;
                }

                $state = 'normal';
            }
        }

        return $result;
    }

    private static function dollarQuoteDelimiter(string $sql, int $offset): ?string
    {
        if (!preg_match('/\G\$[A-Za-z_][A-Za-z0-9_]*\$|\G\$\$/A', $sql, $matches, 0, $offset)) {
            return null;
        }

        return $matches[0];
    }
}
