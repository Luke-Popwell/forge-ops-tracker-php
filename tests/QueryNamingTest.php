<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\QueryNaming;
use PHPUnit\Framework\TestCase;

final class QueryNamingTest extends TestCase
{
    public function testExtractsTheTableFromASelectWithAWhereClause(): void
    {
        $sql = 'SELECT "auth_user"."id", "auth_user"."username" FROM "auth_user" WHERE "auth_user"."id" = ?';

        self::assertSame('SELECT auth_user', QueryNaming::transactionName($sql));
    }

    public function testExtractsTheTableFromADelete(): void
    {
        $sql = 'DELETE FROM "sessions" WHERE "sessions"."id" = ?';

        self::assertSame('DELETE sessions', QueryNaming::transactionName($sql));
    }

    public function testExtractsTheTableFromAnInsert(): void
    {
        $sql = 'INSERT INTO "orders" ("id", "total") VALUES (?, ?)';

        self::assertSame('INSERT INTO orders', QueryNaming::transactionName($sql));
    }

    public function testExtractsTheTableFromAnUpdate(): void
    {
        $sql = 'UPDATE "orders" SET "total" = ? WHERE "orders"."id" = ?';

        self::assertSame('UPDATE orders', QueryNaming::transactionName($sql));
    }

    public function testExtractsTheTableRegardlessOfQuotingStyle(): void
    {
        // Laravel's own query grammars quote identifiers differently per driver: backticks for
        // MySQL, double quotes for SQLite/Postgres, and none at all for a simple, unquoted name.
        // All three need to resolve to the same low-cardinality name.
        self::assertSame('SELECT users', QueryNaming::transactionName('SELECT * FROM users WHERE id = ?'));
        self::assertSame('SELECT users', QueryNaming::transactionName('SELECT * FROM `users` WHERE id = ?'));
    }

    public function testFallsBackToTheFirstKeywordWhenNoTableNameIsFound(): void
    {
        self::assertSame('SELECT', QueryNaming::transactionName('select 1'));
        self::assertSame('PRAGMA', QueryNaming::transactionName('PRAGMA foreign_keys = ON'));
        self::assertSame('BEGIN', QueryNaming::transactionName('BEGIN'));
    }

    public function testFallsBackToALiteralSqlForABlankString(): void
    {
        self::assertSame('SQL', QueryNaming::transactionName(''));
        self::assertSame('SQL', QueryNaming::transactionName('   '));
    }
}
