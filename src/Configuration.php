<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Holds a single ForgeOps DSN plus everything else the client needs to
 * build and deliver events. Mirrors gems/forge_ops_tracker's Configuration
 * -- a single DSN string carries both the ingestion URL and
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
        // not assumed) -- a bad DSN just parses with no 'scheme'/'user'
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
