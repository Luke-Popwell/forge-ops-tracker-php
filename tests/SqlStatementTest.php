<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\EventBuilder;
use ForgeOps\Tracker\SqlStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Stands in for Laravel's QueryException, which exposes the statement as getSql(). */
final class FakeQueryException extends RuntimeException
{
    public function __construct(string $message, private string $sql)
    {
        parent::__construct($message);
    }

    public function getSql(): string
    {
        return $this->sql;
    }
}

/** Stands in for Doctrine DBAL's Query value object. */
final class FakeDoctrineQuery
{
    public function __construct(private string $sql)
    {
    }

    public function getSQL(): string
    {
        return $this->sql;
    }
}

/** Stands in for Doctrine DBAL's DriverException, which exposes getQuery(). */
final class FakeDriverException extends RuntimeException
{
    public function __construct(string $message, private FakeDoctrineQuery $query)
    {
        parent::__construct($message);
    }

    public function getQuery(): FakeDoctrineQuery
    {
        return $this->query;
    }
}

final class SqlStatementTest extends TestCase
{
    public function testFindsTheStatementOffLaravelAndDoctrineStyleExceptions(): void
    {
        self::assertSame('SELECT 1', SqlStatement::findIn(new FakeQueryException('boom', 'SELECT 1')));
        self::assertSame('SELECT 2', SqlStatement::findIn(new FakeDriverException('boom', new FakeDoctrineQuery('SELECT 2'))));
    }

    public function testWalksThePreviousChainAndReturnsNullWithoutSql(): void
    {
        $wrapped = new RuntimeException('refund failed', 0, new FakeQueryException('db', 'CALL refund_order(1)'));

        self::assertSame('CALL refund_order(1)', SqlStatement::findIn($wrapped));
        self::assertNull(SqlStatement::findIn(new RuntimeException('nope')));
        self::assertNull(SqlStatement::findIn(new FakeQueryException('blank', '  ')));
    }

    public function testMasksStringsAndNumbersButNotIdentifiersOrPlaceholders(): void
    {
        self::assertSame(
            'SELECT * FROM orders2 WHERE email = ? AND id = ? AND x = $1',
            SqlStatement::mask("SELECT * FROM orders2 WHERE email = 'a@b.co' AND id = 42 AND x = \$1"),
        );
        self::assertSame('SELECT price * ? FROM t', SqlStatement::mask('SELECT price * 1.5 FROM t'));
    }

    public function testMasksAnEscapedQuoteACutOffStringAndADollarQuotedBody(): void
    {
        self::assertSame('EXEC sp_x @t = ?', SqlStatement::mask("EXEC sp_x @t = 'it''s'"));
        self::assertSame('SELECT ? WHERE n = ?', SqlStatement::mask("SELECT 1 WHERE n = 'oops"));
        self::assertSame('DO ?', SqlStatement::mask('DO $b$ BEGIN PERFORM 1; END $b$'));
    }

    /**
     * The server's canonical masking cases (statement, db.system, expected), copied in whole so
     * every SDK's port is checked against exactly the same set.
     *
     * @return list<array{string, ?string, string}>
     */
    public static function serverCases(): array
    {
        return [
            ['SELECT * FROM orders WHERE email = \'a@b.co\' AND id = 42 LIMIT 10', null, 'SELECT * FROM orders WHERE email = ? AND id = ? LIMIT ?'],
            ['EXEC sp_note @text = \'it\'\'s broken\'', null, 'EXEC sp_note @text = ?'],
            ['SELECT 1 WHERE name = \'unterminated', null, 'SELECT ? WHERE name = ?'],
            ['DO $body$ BEGIN PERFORM 1; END $body$', null, 'DO ?'],
            ['SELECT "user id" FROM orders2 WHERE id = $1 AND v = sp_v2(?)', null, 'SELECT "user id" FROM orders2 WHERE id = $1 AND v = sp_v2(?)'],
            ['SELECT price * 1.5 FROM t', null, 'SELECT price * ? FROM t'],
            ['SELECT * FROM users WHERE name = E\'o\\\'brien\' AND id = 1', null, 'SELECT * FROM users WHERE name = ? AND id = ?'],
            ['SELECT * FROM users WHERE name = \'o\\\'brien\' AND id = 1', null, 'SELECT * FROM users WHERE name = ? AND id = ?'],
            ['SELECT * FROM t WHERE b = X\'DEADBEEF\' AND s = N\'uni\' AND u = U&\'d\\0061t\' AND e = e\'x\'', null, 'SELECT * FROM t WHERE b = ? AND s = ? AND u = ? AND e = ?'],
            ['SELECT * FROM t WHERE a LIKE\'%secret%\'', null, 'SELECT * FROM t WHERE a LIKE?'],
            ['SELECT * FROM t WHERE f = 0x1F AND b = 0b101 AND n = 3e10 AND m = 1.5E-3 AND k = .5', null, 'SELECT * FROM t WHERE f = ? AND b = ? AND n = ? AND m = ? AND k = ?'],
            ['SELECT e, t.col, 1e5e FROM t', null, 'SELECT e, t.col, 1e5e FROM t'],
            ['SELECT "user id" FROM t WHERE token = "abc123secret"', 'mysql', 'SELECT ? FROM t WHERE token = ?'],
            ['SELECT "user id" FROM t WHERE token = "abc123secret"', 'MariaDB', 'SELECT ? FROM t WHERE token = ?'],
            ['SELECT "user id" FROM t WHERE token = "abc123secret"', 'postgresql', 'SELECT "user id" FROM t WHERE token = "abc123secret"'],
            ['SELECT "user id" FROM t WHERE token = "abc123secret"', null, 'SELECT "user id" FROM t WHERE token = "abc123secret"'],
            ['SELECT * FROM t WHERE a = \'x\' AND b = 9', null, 'SELECT * FROM t WHERE a = ? AND b = ?'],
            ['SELECT * FROM t WHERE a = ? AND b = ?', null, 'SELECT * FROM t WHERE a = ? AND b = ?'],
            ['SELECT * FROM t WHERE path = \'C:\\\\dir\\\\\' AND n = 5', null, 'SELECT * FROM t WHERE path = ? AND n = ?'],
            ['INSERT INTO t (a, b) VALUES (-5, +3.25e+2)', null, 'INSERT INTO t (a, b) VALUES (-?, +?)'],
            ['SELECT * FROM t WHERE a = \'secret\\', null, 'SELECT * FROM t WHERE a = ?'],
            ['SELECT * FROM t WHERE a = "secret\\', 'mysql', 'SELECT * FROM t WHERE a = ?'],
        ];
    }

    #[DataProvider('serverCases')]
    public function testMasksExactlyAsTheServerDoes(string $statement, ?string $system, string $expected): void
    {
        self::assertSame($expected, SqlStatement::mask($statement, $system));
        self::assertSame($expected, SqlStatement::mask($expected, $system));
    }

    public function testMasksAStringLongerThanTheJitStackWouldAllowInItsOriginalForm(): void
    {
        self::assertSame('SELECT ? AND n = ?', SqlStatement::mask("SELECT '" . str_repeat('a', 100000) . "' AND n = 5"));
        self::assertSame('SELECT ? FROM t', SqlStatement::mask('SELECT "' . str_repeat('xy', 50000) . '" FROM t', 'mysql'));
    }

    public function testMaskForSpanPassesTheDriverOnAsTheDbSystem(): void
    {
        self::assertSame('SELECT ? FROM t', SqlStatement::maskForSpan('SELECT "secret" FROM t', ' MariaDB '));
        self::assertSame('SELECT "id" FROM t', SqlStatement::maskForSpan('SELECT "id" FROM t', 'pgsql'));
    }

    public function testIsIdempotentTruncatesAndReturnsNullForBlank(): void
    {
        $once = SqlStatement::mask("SELECT * FROM t WHERE a = 'x' AND b = 9");
        self::assertSame($once, SqlStatement::mask($once));
        self::assertSame(SqlStatement::MAX_LENGTH + 3, strlen((string) SqlStatement::mask('SELECT ' . str_repeat('a, ', 3000) . ' b')));
        self::assertNull(SqlStatement::mask('  '));
        self::assertNull(SqlStatement::mask(null));
    }

    public function testFindsAStoredProcedureWithItsSchema(): void
    {
        self::assertSame(
            ['operation' => 'EXEC', 'procedures' => ['dbo.sp_refund_order'], 'relations' => []],
            SqlStatement::objects('EXEC dbo.sp_refund_order @id = ?'),
        );
        self::assertSame(['refund_order'], SqlStatement::objects('CALL refund_order(?, ?)')['procedures']);
        self::assertSame(['refund_order'], SqlStatement::objects('SELECT refund_order(?, ?)')['procedures']);
    }

    public function testFindsViewsJoinedTablesAndTableFunctions(): void
    {
        self::assertSame(
            ['v_totals', 'public.customers'],
            SqlStatement::objects('SELECT * FROM v_totals t JOIN public.customers c ON c.id = t.id')['relations'],
        );
        self::assertSame(['get_open_orders'], SqlStatement::objects('SELECT * FROM get_open_orders(?) o')['procedures']);
    }

    public function testDoesNotMisreadColumnListsOrBuiltinsAndReturnsNullForGarbage(): void
    {
        self::assertSame([], SqlStatement::objects('INSERT INTO audit_log (a) VALUES (?)')['procedures']);
        self::assertSame([], SqlStatement::objects('SELECT count(*) FROM orders')['procedures']);
        self::assertNull(SqlStatement::objects('garbage'));
    }

    public function testInitAcceptsTheTwoSqlOptions(): void
    {
        $config = \ForgeOps\Tracker\ForgeOpsTracker::init(captureSqlObjects: false, captureSqlStatement: true, installExceptionHandler: false);

        self::assertFalse($config->captureSqlObjects);
        self::assertTrue($config->captureSqlStatement);
    }

    private function builder(bool $objects = true, bool $statement = false): EventBuilder
    {
        $config = new Configuration();
        $config->environment = 'production';
        $config->captureSqlObjects = $objects;
        $config->captureSqlStatement = $statement;

        return new EventBuilder($config);
    }

    public function testEventBuilderSendsTheProcedureNameButNotTheStatementByDefault(): void
    {
        $error = new FakeQueryException('boom', "EXEC dbo.sp_refund_order @order_id = 8814, @note = 'a@b.co'");

        $payload = $this->builder()->build($error);

        self::assertSame(['dbo.sp_refund_order'], $payload['sql_objects']['procedures']);
        self::assertArrayNotHasKey('sql_statement', $payload);
    }

    public function testEventBuilderSendsTheMaskedStatementWhenOptedInAndNothingWhenOff(): void
    {
        $error = new FakeQueryException('boom', "EXEC dbo.sp_refund_order @order_id = 8814, @note = 'a@b.co'");

        self::assertSame(
            'EXEC dbo.sp_refund_order @order_id = ?, @note = ?',
            $this->builder(true, true)->build($error)['sql_statement'],
        );

        $off = $this->builder(false, false)->build($error);
        self::assertArrayNotHasKey('sql_objects', $off);
        self::assertArrayNotHasKey('sql_statement', $off);
        self::assertArrayNotHasKey('sql_objects', $this->builder()->build(new RuntimeException('nope')));
    }
}
