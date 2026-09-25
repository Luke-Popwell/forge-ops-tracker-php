<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * The EXPLAIN rate limits: each distinct masked statement at most once per 10 minutes, and at most
 * 10 EXPLAINs per rolling minute. PHP-FPM starts every request with fresh statics, so an in-memory
 * counter would reset on every request and limit nothing; the state lives in a small JSON file in
 * the system temp directory instead (locked while it's read and written), the same approach
 * ChangeSnapshot's marker file takes. That makes the limits per server rather than per process,
 * which is only ever stricter. When the file can't be opened or locked, nothing is allowed.
 */
class QueryPlanRateLimiter
{
    public const PER_STATEMENT_SECONDS = 600;
    public const MAX_PER_MINUTE = 10;

    private string $path;

    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param (\Closure(): float)|null $clock */
    public function __construct(Configuration $configuration, ?string $path = null, ?\Closure $clock = null)
    {
        $this->path = $path ?? sys_get_temp_dir() . '/forge-ops-tracker-explain-' . substr(sha1((string) $configuration->dsn), 0, 12) . '.json';
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function allow(string $key): bool
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $now = ($this->clock)();
            $state = json_decode((string) stream_get_contents($handle), true);
            $statements = is_array($state) && is_array($state['statements'] ?? null) ? $state['statements'] : [];
            $recent = is_array($state) && is_array($state['recent'] ?? null) ? $state['recent'] : [];

            $statements = array_filter($statements, static fn ($at): bool => is_numeric($at) && $now - (float) $at < self::PER_STATEMENT_SECONDS);
            $recent = array_values(array_filter($recent, static fn ($at): bool => is_numeric($at) && $now - (float) $at < 60));

            $hash = sha1($key);
            if (isset($statements[$hash]) || count($recent) >= self::MAX_PER_MINUTE) {
                return false;
            }

            $statements[$hash] = $now;
            $recent[] = $now;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['statements' => $statements, 'recent' => $recent]));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
