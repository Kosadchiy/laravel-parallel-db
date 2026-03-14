<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use Kosadchiy\LaravelParallelDb\Support\PostgresPlaceholderConverter;
use PHPUnit\Framework\TestCase;

final class PostgresPlaceholderConverterTest extends TestCase
{
    public function testConvertsRealPlaceholdersOnly(): void
    {
        $sql = <<<'SQL'
select * from docs
where id = ?
and note = 'literal ?'
and title = "double ?"
and meta ? 'tag'
and flags ?| array['a?', 'b']
and extra = ?
-- ignored ?
/* block ? comment */
SQL;

        $converted = PostgresPlaceholderConverter::questionMarksToPgParams($sql, [10, 'tail']);

        self::assertStringContainsString('id = $1', $converted['sql']);
        self::assertStringContainsString('extra = $2', $converted['sql']);
        self::assertStringContainsString("note = 'literal ?'", $converted['sql']);
        self::assertStringContainsString("meta ? 'tag'", $converted['sql']);
        self::assertStringContainsString("?| array['a?', 'b']", $converted['sql']);
        self::assertSame([10, 'tail'], $converted['bindings']);
    }

    public function testIgnoresDollarQuotedStrings(): void
    {
        $sql = <<<'SQL'
select $$literal ?$$ as a, $tag$block ?$tag$ as b
from docs
where id = ?
SQL;

        $converted = PostgresPlaceholderConverter::questionMarksToPgParams($sql, [7]);

        self::assertStringContainsString('$$literal ?$$', $converted['sql']);
        self::assertStringContainsString('$tag$block ?$tag$', $converted['sql']);
        self::assertStringContainsString('where id = $1', $converted['sql']);
    }

    public function testIgnoresEscapedQuotesAndEndOfFileComments(): void
    {
        $sql = <<<'SQL'
select * from docs
where note = 'it''s still ?'
and title = "a ""quoted"" ?"
and payload = ?
-- trailing ? comment
SQL;

        $converted = PostgresPlaceholderConverter::questionMarksToPgParams($sql, ['body']);

        self::assertStringContainsString("note = 'it''s still ?'", $converted['sql']);
        self::assertStringContainsString('title = "a ""quoted"" ?"', $converted['sql']);
        self::assertStringContainsString('payload = $1', $converted['sql']);
        self::assertStringContainsString('-- trailing ? comment', $converted['sql']);
    }

    public function testConvertsPlaceholdersNextToPostgresCastsAndKeepsJsonOperators(): void
    {
        $sql = "select ?::jsonb ? 'key', data ?& array['a', 'b'], payload = ?";

        $converted = PostgresPlaceholderConverter::questionMarksToPgParams($sql, ['{}', 'tail']);

        self::assertSame("select \$1::jsonb ? 'key', data ?& array['a', 'b'], payload = \$2", $converted['sql']);
    }

    public function testRejectsMismatchedPlaceholderCounts(): void
    {
        $this->expectException(ParallelExecutionException::class);

        PostgresPlaceholderConverter::questionMarksToPgParams(
            "select * from users where id = ? and meta ? 'tag'",
            [],
        );
    }
}
