<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Finds the SQL behind a database error and reduces it to something safe to send: the names of
 * the stored procedures, tables and views it touched, and (only if
 * Configuration::$captureSqlStatement is on) the statement itself with every string and number
 * replaced by "?". Ported from gems/forge_ops_tracker's SqlStatement, which is itself ported from
 * the server's own SqlStatementMasker/SqlObjectExtractor: same rules everywhere, and the server
 * applies them again on arrival, so a difference here can only ever mean less is masked
 * client-side, never that something unmasked gets stored.
 *
 * Deliberately a single pass over a few patterns, not a SQL parser.
 */
final class SqlStatement
{
    public const MASK = '?';
    public const MAX_LENGTH = 4000;
    private const MAX_NAMES = 10;
    private const MAX_NAME_LENGTH = 200;
    private const MAX_CAUSE_DEPTH = 5;

    /** db.system values where "double quotes" are a string, not a name. */
    private const DOUBLE_QUOTED_STRING_SYSTEMS = ['mysql', 'mariadb'];

    // The prefix only counts as one when it isn't the end of a word, but the quote always does:
    // LIKE'%x%', with no space, is still a string. A backslash can be the statement's last character
    // ('secret\ cut off mid-escape), and the string is still masked to the end. A string's body is
    // the Ruby original's (?:[^'\\]|\\(?:.|\z)|'')* unrolled into runs, which matches exactly the
    // same text: PCRE's JIT keeps a backtracking point for every repetition, so the original form
    // runs out of JIT stack on any string over about 8,000 characters, where this only does after
    // about 5,000 escapes in one string. Either way preg_replace() then fails, and mask() returns
    // null rather than anything unmasked.
    private const STRING = <<<'RE'
        (?:(?<![\w$])(?:[EeXxNnBb]|[Uu]&))?'[^'\\]*(?:(?:\\(?:.|\z)|'')[^'\\]*)*(?:'|\z)
        RE;
    private const DOUBLE_QUOTED_STRING = <<<'RE'
        "[^"\\]*(?:(?:\\(?:.|\z)|"")[^"\\]*)*(?:"|\z)
        RE;
    private const DOLLAR_QUOTED = <<<'RE'
        (?<tag>\$[A-Za-z_]*\$).*?(?:\k<tag>|\z)
        RE;
    // [0-9A-Fa-f] where the Ruby original has \h: in PCRE, \h is horizontal whitespace.
    private const NUMBER = <<<'RE'
        (?<![\w$.])(?:0[xX][0-9A-Fa-f]+|0[bB][01]+|(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)(?!\w)
        RE;

    private const LITERAL = '~' . self::STRING . '|' . self::DOLLAR_QUOTED . '|' . self::NUMBER . '~s';
    private const LITERAL_WITH_DOUBLE_QUOTES = '~' . self::STRING . '|' . self::DOUBLE_QUOTED_STRING . '|'
        . self::DOLLAR_QUOTED . '|' . self::NUMBER . '~s';

    private const PART = '(?:[\w$#@]+|"[^"]+"|\[[^\]]+\]|`[^`]+`)';
    private const NAME = self::PART . '(?:\.' . self::PART . ')*';
    private const OPERATIONS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'WITH', 'CALL', 'EXEC', 'EXECUTE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
    ];
    private const BUILTINS = [
        'count', 'sum', 'min', 'max', 'avg', 'now', 'coalesce', 'nullif', 'lower', 'upper', 'length', 'concat',
        'cast', 'date_trunc', 'current_timestamp', 'current_date', 'row_number', 'rank', 'json_build_object',
        'json_agg', 'array_agg',
    ];
    private const KEYWORDS_NOT_NAMES = ['select', 'set', 'values', 'where', 'lateral', 'only', 'unnest', 'generate_series'];

    /**
     * The raw statement off the error itself or, for an app that wraps a database error in its
     * own exception, off whatever it was raised from (getPrevious()). Laravel's QueryException
     * exposes it as getSql(); Doctrine DBAL's DriverException as getQuery(), an object with its
     * own getSQL(). Raw PDOException exposes nothing.
     */
    public static function findIn(Throwable $error): ?string
    {
        $depth = 0;
        for ($current = $error; $current !== null && $depth < self::MAX_CAUSE_DEPTH; $current = $current->getPrevious()) {
            $statement = self::statementOf($current);
            if ($statement !== null) {
                return $statement;
            }
            $depth++;
        }

        return null;
    }

    private static function statementOf(Throwable $error): ?string
    {
        foreach (['getSql', 'getSQL'] as $accessor) {
            if (method_exists($error, $accessor)) {
                $value = $error->$accessor();
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        }
        if (method_exists($error, 'getQuery')) {
            $query = $error->getQuery();
            if (is_string($query) && trim($query) !== '') {
                return $query;
            }
            if (is_object($query)) {
                foreach (['getSQL', 'getSql'] as $accessor) {
                    if (method_exists($query, $accessor)) {
                        $value = $query->$accessor();
                        if (is_string($value) && trim($value) !== '') {
                            return $value;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * $statement with every string and number replaced by "?", or null when it's blank. Pass the
     * query's db.system as $system when it's known: on "mysql" and "mariadb", "double quotes" are a
     * string too, so they're masked there and left alone everywhere else.
     */
    public static function mask(?string $statement, ?string $system = null): ?string
    {
        if ($statement === null || trim($statement) === '') {
            return null;
        }
        $doubleQuotes = $system !== null && in_array(strtolower($system), self::DOUBLE_QUOTED_STRING_SYSTEMS, true);
        $masked = preg_replace($doubleQuotes ? self::LITERAL_WITH_DOUBLE_QUOTES : self::LITERAL, self::MASK, $statement);
        if ($masked === null) {
            return null;
        }

        return strlen($masked) > self::MAX_LENGTH ? substr($masked, 0, self::MAX_LENGTH) . '...' : $masked;
    }

    /**
     * The masked statement a database span carries as "db.statement": mask() above, capped at
     * exactly MAX_LENGTH characters. null for a missing or blank statement. $dbSystem is the
     * span's driver or db.system name, passed on to mask() (normalized the way QueryPlans::dbSystem()
     * does it).
     */
    public static function maskForSpan(?string $statement, ?string $dbSystem = null): ?string
    {
        $masked = self::mask($statement, QueryPlans::dbSystem($dbSystem));

        return $masked === null ? null : substr($masked, 0, self::MAX_LENGTH);
    }

    /**
     * Takes an already-masked statement (so a keyword inside a string value can't be mistaken for
     * SQL). Returns null when nothing recognizable was found.
     *
     * @return array{operation?: string, procedures: list<string>, relations: list<string>}|null
     */
    public static function objects(?string $masked): ?array
    {
        if ($masked === null || trim($masked) === '') {
            return null;
        }

        $sql = preg_replace('~\b(?:EXTRACT|SUBSTRING|TRIM|OVERLAY)\s*\([^()]*\)~i', ' ', $masked) ?? $masked;
        $procedures = [];
        $relations = [];

        if (preg_match_all('~\b(?:CALL|EXEC(?:UTE)?|PERFORM)\s+(?!IMMEDIATE\b|FUNCTION\b|PROCEDURE\b)(' . self::NAME . ')~i', $sql, $calls)) {
            $procedures = $calls[1];
        }

        if (preg_match_all('~\b(FROM|JOIN|INTO|UPDATE|TABLE)\s+(' . self::NAME . ')(\s*\()?~i', $sql, $found, PREG_SET_ORDER)) {
            foreach ($found as $match) {
                $name = $match[2];
                if (in_array(strtolower($name), self::KEYWORDS_NOT_NAMES, true)) {
                    continue;
                }
                $functionCall = isset($match[3]) && $match[3] !== '' && in_array(strtoupper($match[1]), ['FROM', 'JOIN'], true);
                if ($functionCall) {
                    $procedures[] = $name;
                } else {
                    $relations[] = $name;
                }
            }
        }

        if (
            preg_match('~\A\s*SELECT\s+(' . self::NAME . ')\s*\(~i', $sql, $select) === 1
            && !in_array(strtolower($select[1]), self::BUILTINS, true)
            && preg_match('~\bFROM\b~i', $sql) !== 1
        ) {
            $procedures[] = $select[1];
        }

        $operation = preg_match('~\A\s*(\w+)~', $sql, $first) === 1 ? strtoupper($first[1]) : '';
        $result = [];
        if (in_array($operation, self::OPERATIONS, true)) {
            $result['operation'] = $operation;
        }
        $result['procedures'] = self::clean($procedures);
        $result['relations'] = self::clean($relations);
        if ($result['procedures'] === [] && $result['relations'] === [] && !isset($result['operation'])) {
            return null;
        }

        return $result;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function clean(array $names): array
    {
        $cleaned = [];
        foreach ($names as $raw) {
            $name = substr(trim($raw), 0, self::MAX_NAME_LENGTH);
            if (preg_match('~\A' . self::NAME . '\z~', $name) === 1 && !in_array($name, $cleaned, true)) {
                $cleaned[] = $name;
            }
        }

        return array_slice($cleaned, 0, self::MAX_NAMES);
    }
}
