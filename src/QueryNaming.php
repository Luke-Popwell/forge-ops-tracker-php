<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Neither Laravel's own QueryExecuted event (see Integrations/Laravel/ForgeOpsTrackerPerformanceMiddleware)
 * nor a future Doctrine integration hands over a friendly auto-generated label the way Rails' own
 * sql.active_record payload does; only the raw SQL string. Raw SQL is both too high-cardinality
 * for a transaction_name (every slightly different query shape would become its own bucket) and a
 * real, if usually parameterized, risk of leaking a literal value. A direct port of the identical
 * regex already written for the Python SDK this session: extracts "<VERB> <table>" ("SELECT
 * auth_user", "INSERT INTO orders") for the two shapes that actually carry a table name in a
 * predictable place, falling back to just the first SQL keyword ("PRAGMA", "BEGIN") for anything
 * else, or the literal string "SQL" if even that fails. Not a real SQL parser, deliberately: the
 * same "close enough, low-cardinality" trade-off Rails' own SCHEMA/cached-query skip already
 * accepts for the Ruby SDK.
 */
final class QueryNaming
{
    private const SELECT_DELETE = '/^\s*(SELECT|DELETE)\b.*?\bFROM\s+["\'`]?(\w+)/is';
    private const INSERT_UPDATE = '/^\s*(INSERT INTO|UPDATE)\s+["\'`]?(\w+)/i';

    public static function transactionName(string $sql): string
    {
        if (preg_match(self::SELECT_DELETE, $sql, $matches) || preg_match(self::INSERT_UPDATE, $sql, $matches)) {
            return strtoupper($matches[1]) . ' ' . $matches[2];
        }

        $trimmed = trim($sql);
        if ($trimmed === '') {
            return 'SQL';
        }

        $firstWord = strtok($trimmed, " \t\n\r");

        return strtoupper($firstWord === false ? $trimmed : $firstWord);
    }
}
