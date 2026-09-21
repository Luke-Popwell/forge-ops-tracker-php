<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Holds a single ForgeOps DSN plus everything else the client needs to
 * build and deliver events. Mirrors gems/forge_ops_tracker's Configuration:
 * a single DSN string carries both the ingestion URL and
 * the project's api_key: "https://<api_key>@host/api/v1/events".
 */
final class Configuration
{
    public ?string $dsn;
    public string $environment;
    public ?string $release;
    public ?string $serverName;

    /**
     * Used to decide whether a backtrace frame is "in_app": a frame's
     * file path is compared against this root. Like the Ruby gem (and
     * unlike the .NET SDK, where a compiled assembly's file path never
     * matches its original source location), PHP runs interpreted
     * directly from real .php files on disk, so file-path matching is
     * the correct approach here too. Defaults to the current working
     * directory; set explicitly if that doesn't match your app's actual
     * layout.
     */
    public string $appRoot;

    /** @var string[] */
    public array $enabledEnvironments = ['production', 'staging'];

    public int $queueSize = 1000;
    public float $timeout = 2.0; // seconds
    public bool $scrubPii = true;

    /**
     * Whether EventBuilder reads a few lines of source off disk around each
     * in-app frame's culprit line (see EventBuilder::attachSourceContext()).
     * Defaults to true so a snippet shows up with no extra setup, but this
     * flag by itself isn't the real safeguard against sending proprietary
     * source code somewhere it shouldn't go: ForgeOps' own per-project
     * setting is the durable, server-enforced off switch, since it applies
     * no matter what this flag happens to be set to on any given
     * deployment, and can't quietly drift back on the way a local config
     * value could. Set this to false too if this host app should never even
     * attempt that disk read in the first place.
     */
    public bool $captureSourceContext = true;

    /**
     * When an error comes from a database call (Laravel's QueryException, Doctrine DBAL's
     * DriverException, and anything that wraps one), send the names of the stored procedure,
     * table and view its SQL touched, so an issue says where to start looking. Names are
     * identifiers, never values, which is why this defaults on. $captureSqlStatement is the
     * separate, opt-in step of also sending the statement itself, with every string and number
     * replaced by "?"; off by default because even a masked statement describes your schema, and
     * ForgeOps' own per-project setting is what durably governs whether the server stores it. See
     * SqlStatement.
     */
    public bool $captureSqlObjects = true;

    public bool $captureSqlStatement = false;

    /**
     * Whether every request through a framework integration also counts as a session (crash-free
     * unless an unhandled exception, or a 5xx response, actually affects it), reported to give
     * ForgeOps a crash-free rate per release. On by default, the same "on unless you turn it off"
     * posture error tracking itself already has.
     */
    public bool $trackSessions = true;

    /**
     * Whether every request through a framework integration also reports its own duration, so a
     * dashboard widget on ForgeOps can show which parts of your app are actually slow. On by
     * default, same posture as trackSessions above.
     */
    public bool $trackPerformance = true;

    /**
     * Whether every request through a framework integration also records a breadcrumb trail (SQL
     * queries, the request/controller lifecycle, queue job runs) leading up to whatever error
     * eventually gets reported. On by default, same posture as trackSessions/trackPerformance
     * above. Gates only the automatic sources; ForgeOpsTracker::addBreadcrumb() works regardless
     * of this flag, the same "manual API isn't gated by it" contract the Ruby gem's own
     * track_breadcrumbs documents.
     */
    public bool $trackBreadcrumbs = true;

    /**
     * Whether every request through a framework integration starts a trace and reports it (when
     * slow) to /spans. ForgeOpsTracker::span()/recordSpan() only record inside a trace, so this
     * gates the whole feature. On by default, same posture as the flags above.
     */
    public bool $trackTracing = true;

    /** A trace is only sent when its root span took at least this many seconds. */
    public float $traceCaptureThreshold = 1.0;

    /**
     * Oldest entry dropped once this many have accumulated in a single request (or queue job): the
     * same bounded-ring-buffer reasoning gems/forge_ops_tracker's own Configuration#max_breadcrumbs
     * already documents, so a request that runs a very large number of queries doesn't grow the
     * trail (and the payload it rides in) without bound.
     */
    public int $maxBreadcrumbs = 30;

    /** @var (callable(string): void)|null */
    public $logger = null;

    public function __construct()
    {
        $this->dsn = getenv('FORGE_OPS_DSN') ?: null;
        $this->environment = getenv('FORGE_OPS_ENVIRONMENT') ?: 'development';
        $this->release = getenv('FORGE_OPS_RELEASE') ?: null;
        $this->serverName = gethostname() ?: null;
        $this->appRoot = getcwd() ?: '';
    }

    public function apiKey(): ?string
    {
        $parsed = $this->parsedDsn();
        if ($parsed === null || !isset($parsed['user'])) {
            return null;
        }

        return rawurldecode($parsed['user']);
    }

    /**
     * The ingestion URL with credentials stripped out (they travel as the
     * Authorization header instead, not embedded in the request URI).
     */
    public function ingestionUri(): ?string
    {
        $parsed = $this->parsedDsn();
        if ($parsed === null) {
            return null;
        }

        $uri = ($parsed['scheme'] ?? '') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $uri .= ':' . $parsed['port'];
        }
        $uri .= $parsed['path'] ?? '';
        if (isset($parsed['query'])) {
            $uri .= '?' . $parsed['query'];
        }

        return $uri;
    }

    /**
     * Same derivation as ingestionUri(), with the trailing "/events" swapped for
     * "/session_checkins": one DSN, two endpoints, matching every other client's own
     * sessionCheckinsUri()/SessionCheckinsUri()/session_checkins_uri().
     */
    public function sessionCheckinsUri(): ?string
    {
        $uri = $this->ingestionUri();
        if ($uri === null) {
            return null;
        }

        if (!str_ends_with($uri, '/events')) {
            return $uri;
        }

        return substr($uri, 0, -strlen('/events')) . '/session_checkins';
    }

    /**
     * Same derivation again, swapping the trailing "/events" for "/performance_samples".
     */
    public function performanceSamplesUri(): ?string
    {
        $uri = $this->ingestionUri();
        if ($uri === null) {
            return null;
        }

        if (!str_ends_with($uri, '/events')) {
            return $uri;
        }

        return substr($uri, 0, -strlen('/events')) . '/performance_samples';
    }

    /**
     * Same derivation again, swapping the trailing "/events" for "/spans".
     */
    public function customMetricsUri(): ?string
    {
        return $this->swapEventsSuffix('/custom_metrics');
    }

    public function infrastructureMetricsUri(): ?string
    {
        return $this->swapEventsSuffix('/infrastructure_metrics');
    }

    private function swapEventsSuffix(string $replacement): ?string
    {
        $uri = $this->ingestionUri();
        if ($uri === null) {
            return null;
        }

        return str_ends_with($uri, '/events') ? substr($uri, 0, -strlen('/events')) . $replacement : $uri;
    }

    public function spansUri(): ?string
    {
        $uri = $this->ingestionUri();
        if ($uri === null) {
            return null;
        }

        if (!str_ends_with($uri, '/events')) {
            return $uri;
        }

        return substr($uri, 0, -strlen('/events')) . '/spans';
    }

    public function isEnabled(): bool
    {
        return $this->dsn !== null && $this->dsn !== ''
            && $this->apiKey() !== null
            && in_array($this->environment, $this->enabledEnvironments, true);
    }

    public function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }

    /** @return array<string, mixed>|null */
    private function parsedDsn(): ?array
    {
        if ($this->dsn === null || $this->dsn === '') {
            return null;
        }

        // parse_url never raises on malformed input (verified directly,
        // not assumed): a bad DSN just parses with no 'scheme'/'user'
        // keys, which apiKey()/ingestionUri() already handle by
        // returning null. It can return false for some inputs, though,
        // so that's still guarded against explicitly.
        $result = parse_url($this->dsn);
        if ($result === false || !isset($result['scheme'])) {
            return null;
        }

        return $result;
    }
}
