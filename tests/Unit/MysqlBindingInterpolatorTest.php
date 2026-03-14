<?php

declare(strict_types=1);

namespace Kosadchiy\LaravelParallelDb\Tests\Unit;

use DateTimeImmutable;
use Kosadchiy\LaravelParallelDb\Exceptions\ParallelExecutionException;
use Kosadchiy\LaravelParallelDb\Support\MysqlBindingInterpolator;
use mysqli;
use PHPUnit\Framework\TestCase;
use Stringable;

final class MysqlBindingInterpolatorTest extends TestCase
{
    public function testInterpolatesRealPlaceholdersOnly(): void
    {
        $sql = <<<'SQL'
select * from users
where name = ?
and note = 'literal ?'
and title = "double ?"
and code = `column?name`
and id = ?
-- ignored ?
# ignored too ?
/* block ? comment */
SQL;

        $interpolated = MysqlBindingInterpolator::interpolate($this->dummyConnection(), $sql, ['Alice', 5], $this->escaper());

        self::assertStringContainsString("name = 'Alice'", $interpolated);
        self::assertStringContainsString('id = 5', $interpolated);
        self::assertStringContainsString("note = 'literal ?'", $interpolated);
        self::assertStringContainsString('-- ignored ?', $interpolated);
        self::assertStringContainsString('/* block ? comment */', $interpolated);
    }

    public function testInterpolatesBackedEnumsAndStringables(): void
    {
        $interpolated = MysqlBindingInterpolator::interpolate(
            $this->dummyConnection(),
            'select * from users where status = ? and ref = ?',
            ['active', new TestStringable('REF-42')],
            $this->escaper(),
        );

        self::assertStringContainsString("status = 'active'", $interpolated);
        self::assertStringContainsString("ref = 'REF-42'", $interpolated);
    }

    public function testIgnoresEscapedQuotesAndEndOfFileComments(): void
    {
        $sql = <<<'SQL'
select * from users
where note = 'it''s still ?'
and title = "a ""quoted"" ?"
and payload = ?
# trailing ? comment
SQL;

        $interpolated = MysqlBindingInterpolator::interpolate(
            $this->dummyConnection(),
            $sql,
            ['body'],
            $this->escaper(),
        );

        self::assertStringContainsString("note = 'it''s still ?'", $interpolated);
        self::assertStringContainsString('title = "a ""quoted"" ?"', $interpolated);
        self::assertStringContainsString("payload = 'body'", $interpolated);
        self::assertStringContainsString('# trailing ? comment', $interpolated);
    }

    public function testFormatsDateTimesWithMicroseconds(): void
    {
        $interpolated = MysqlBindingInterpolator::interpolate(
            $this->dummyConnection(),
            'insert into events(created_at) values (?)',
            [new DateTimeImmutable('2026-03-14 10:11:12.123456')],
            $this->escaper(),
        );

        self::assertStringContainsString("'2026-03-14 10:11:12.123456'", $interpolated);
    }

    public function testRejectsNonFiniteFloats(): void
    {
        $this->expectException(ParallelExecutionException::class);

        MysqlBindingInterpolator::interpolate(
            $this->dummyConnection(),
            'select * from metrics where value = ?',
            [INF],
            $this->escaper(),
        );
    }

    public function testRejectsMismatchedPlaceholderCounts(): void
    {
        $this->expectException(ParallelExecutionException::class);

        MysqlBindingInterpolator::interpolate(
            $this->dummyConnection(),
            'select * from users where id = ? and name = ?',
            [1],
            $this->escaper(),
        );
    }

    /**
     * @return callable(string): string
     */
    private function escaper(): callable
    {
        return static fn (string $value): string => addslashes($value);
    }

    private function dummyConnection(): mysqli
    {
        if (!class_exists(mysqli::class)) {
            self::markTestSkipped('ext-mysqli is not installed.');
        }

        $connection = mysqli_init();

        if (!$connection instanceof mysqli) {
            self::markTestSkipped('Unable to initialize mysqli for interpolation tests.');
        }

        return $connection;
    }
}

final readonly class TestStringable implements Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
