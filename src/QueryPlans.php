<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * The pure pieces of the opt-in EXPLAIN (see QueryPlanner): which statements are safe to EXPLAIN,
 * masking a plan, and building the POST /api/v1/query_plans body. Also the db.system name for a
 * driver, and the data a database span carries.
 */
final class QueryPlans
{
    public const STATEMENT_TIMEOUT = '2s';
    public const MAX_PLAN_BYTES = 65536;

    /**
     * One pass over the statement, leftmost match wins, so a quote inside a comment or a comment
     * marker inside a string can't hide anything: string literals (including dollar-quoted ones)
     * become "?", line and block comments become a space.
     */
    private const LITERALS_AND_COMMENTS = <<<'RE'
        ~'(?:[^']|'')*(?:'|\z)|(?<tag>\$[A-Za-z_]*\$).*?(?:\k<tag>|\z)|(?<comment>--[^\n]*|/\*.*?(?:\*/|\z))~s
        RE;

    private const DB_SYSTEMS = [
        'pgsql' => 'postgresql',
        'postgres' => 'postgresql',
        'sqlsrv' => 'mssql',
        'oci' => 'oracle',
        'oci8' => 'oracle',
    ];

    /**
     * The lowercase db.system for a Laravel/PDO driver name ("pgsql" is "postgresql", "sqlsrv" is
     * "mssql"; "mysql", "mariadb" and "sqlite" as they are), or null when unknown.
     */
    public static function dbSystem(?string $driver): ?string
    {
        if ($driver === null || trim($driver) === '') {
            return null;
        }
        $driver = strtolower(trim($driver));

        return self::DB_SYSTEMS[$driver] ?? $driver;
    }

    /**
     * $data plus "db.statement" (already masked) and "db.system", each only when known.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function spanData(?string $maskedStatement, ?string $dbSystem, array $data = []): array
    {
        if ($maskedStatement !== null) {
            $data['db.statement'] = $maskedStatement;
        }
        $dbSystem = self::dbSystem($dbSystem);
        if ($dbSystem !== null) {
            $data['db.system'] = $dbSystem;
        }

        return $data;
    }

    /**
     * Whether a statement is safe to EXPLAIN: after comments and leading whitespace it starts with
     * SELECT, has no second statement (no ";" except one at the very end), no locking clause (FOR
     * UPDATE, FOR SHARE, FOR NO KEY UPDATE, FOR KEY SHARE), and no INSERT, UPDATE, DELETE or MERGE
     * anywhere (a data-modifying CTE).
     */
    public static function explainable(?string $statement): bool
    {
        if ($statement === null || trim($statement) === '') {
            return false;
        }
        $sql = preg_replace_callback(
            self::LITERALS_AND_COMMENTS,
            static fn (array $match): string => isset($match['comment']) && $match['comment'] !== '' ? ' ' : '?',
            $statement,
        );
        if ($sql === null || preg_match('~\A\s*SELECT\b~i', $sql) !== 1) {
            return false;
        }
        $body = rtrim($sql);
        if (str_ends_with($body, ';')) {
            $body = substr($body, 0, -1);
        }
        if (str_contains($body, ';')) {
            return false;
        }

        return preg_match('~\bFOR\s+(?:NO\s+KEY\s+UPDATE|UPDATE|KEY\s+SHARE|SHARE)\b~i', $sql) !== 1
            && preg_match('~\b(?:INSERT|UPDATE|DELETE|MERGE)\b~i', $sql) !== 1;
    }

    /**
     * Every string in the plan masked with SqlStatement::mask() (a plan's "Filter" or "Index Cond"
     * repeats the query's literals); numbers, keys and structure unchanged.
     */
    public static function maskPlan(mixed $value): mixed
    {
        if (is_string($value)) {
            return SqlStatement::mask($value) ?? $value;
        }
        if (is_array($value)) {
            return array_map(self::maskPlan(...), $value);
        }
        if ($value instanceof \stdClass) {
            $masked = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $masked->{$key} = self::maskPlan($item);
            }

            return $masked;
        }

        return $value;
    }

    /**
     * The POST /api/v1/query_plans body, or null when the plan isn't usable (not the JSON array
     * Postgres returns) or its serialized form is over MAX_PLAN_BYTES. $plan is either that JSON
     * text or already decoded. $capturedAt is a microtime(true) value.
     *
     * @return array<string, mixed>|null
     */
    public static function payload(Configuration $configuration, string $maskedStatement, string $dbSystem, mixed $plan, float $durationMs, float $capturedAt): ?array
    {
        if (is_string($plan)) {
            $plan = json_decode($plan);
        }
        if (!is_array($plan) || !array_is_list($plan) || $plan === []) {
            return null;
        }
        $plan = self::maskPlan($plan);
        $encoded = json_encode($plan);
        if ($encoded === false || strlen($encoded) > self::MAX_PLAN_BYTES) {
            return null;
        }

        return [
            'statement' => $maskedStatement,
            'db_system' => $dbSystem,
            'plan' => $plan,
            'duration_ms' => round($durationMs, 2),
            'environment' => $configuration->environment,
            'captured_at' => SpanBuffer::timestamp($capturedAt),
        ];
    }
}
