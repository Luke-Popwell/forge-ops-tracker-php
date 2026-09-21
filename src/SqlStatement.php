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

    private const LITERAL = <<<'RE'
        ~'(?:[^']|'')*(?:'|\z)|(?<tag>\$[A-Za-z_]*\$).*?(?:\k<tag>|\z)|(?<![\w$.])\d+(?:\.\d+)?(?!\w)~s
        RE;

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

    public static function mask(?string $statement): ?string
    {
        if ($statement === null || trim($statement) === '') {
            return null;
        }
        $masked = preg_replace(self::LITERAL, self::MASK, $statement);
        if ($masked === null) {
            return null;
        }

        return strlen($masked) > self::MAX_LENGTH ? substr($masked, 0, self::MAX_LENGTH) . '...' : $masked;
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
