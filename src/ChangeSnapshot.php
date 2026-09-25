<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Composer\InstalledVersions;

/**
 * Tells ForgeOps what this app is running (the PHP version, the Composer package versions, and
 * optionally the names of its environment variables). The server diffs it against the last
 * snapshot for the same project and environment and records whatever changed, so the client stays
 * stateless about what the previous deploy looked like. Mirrors gems/forge_ops_tracker's
 * ChangeSnapshot.
 *
 * Any key that can't be determined reliably is left out rather than guessed at, since the server
 * reads a missing key as "unknown", never as "everything was removed".
 *
 * "Once per process" needs one more step here than in the other clients: under PHP-FPM every
 * request starts with fresh statics, so a static "already sent" flag alone would send a snapshot
 * on every request. send() therefore also keeps a small marker file in the system temp directory
 * holding a hash of the last snapshot it sent for this DSN and environment, and skips sending when
 * the current snapshot hashes the same. A new deploy changes the hash, so it's sent once per host
 * per change (a rollback changes it back, so that's sent too). When the marker can't be written,
 * nothing is sent at all, rather than risk sending on every request.
 */
final class ChangeSnapshot
{
    private const MAX_DEPENDENCIES = 3000;
    private const MAX_NAME_LENGTH = 200;
    private const MAX_VERSION_LENGTH = 100;
    private const MAX_ENV_VAR_NAMES = 3000;

    /**
     * Host-specific variables that differ from one box to the next (or one boot to the next)
     * without anything about the deploy having changed, so a fleet doesn't look like it's changing
     * on every restart. The client's own FORGE_OPS_* settings are dropped too.
     */
    private const ENV_VAR_DENYLIST = [
        'HOSTNAME', 'HOST', 'HOME', 'PATH', 'PWD', 'OLDPWD', 'SHLVL', '_', 'TERM', 'USER', 'LOGNAME',
        'SHELL', 'LANG', 'TMPDIR', 'TZ', 'PORT', 'DYNO', 'INVOCATION_ID', 'JOURNAL_STREAM',
    ];
    private const ENV_VAR_DENYLIST_PATTERNS = [
        '/^LC_/', '/^SYSTEMD_/', '/^MEMORY_PRESSURE_/', '/^KUBERNETES_/', '/^FORGE_OPS_/',
        '/_SERVICE_HOST$/', '/_SERVICE_PORT/', '/_PORT_.*_TCP/',
    ];

    private string $markerDirectory;

    public function __construct(
        private Configuration $configuration,
        private Client $client,
        ?string $markerDirectory = null,
    ) {
        $this->markerDirectory = $markerDirectory ?? sys_get_temp_dir();
    }

    /**
     * Sends the snapshot unless this host already sent this exact one (see the class comment).
     * Returns whether it was delivered. Never throws.
     */
    public function send(): bool
    {
        try {
            $payload = $this->payload();
            $body = json_encode($payload);
            if ($body === false) {
                return false;
            }

            $fingerprint = sha1($body);
            $marker = $this->markerPath();
            if (@file_get_contents($marker) === $fingerprint) {
                return false;
            }
            // Written before sending, and nothing sent if it can't be: a 403 from a plan without
            // change tracking, or a marker that can't be written, must not turn into a POST on
            // every request.
            if (@file_put_contents($marker, $fingerprint, LOCK_EX) === false) {
                $this->configuration->log('[forge-ops-tracker] change snapshot skipped: could not write ' . $marker);

                return false;
            }

            return $this->client->deliverChangeSnapshot($payload);
        } catch (\Throwable $e) {
            $this->configuration->log('[forge-ops-tracker] change snapshot failed: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /** @return array{environment: string, state: array<string, mixed>} */
    public function payload(): array
    {
        $state = ['runtime' => self::runtime()];

        $dependencies = self::dependencies();
        if ($dependencies !== null) {
            $state['dependencies'] = $dependencies;
        }
        if ($this->configuration->trackEnvVarNames) {
            $state['env_var_names'] = self::envVarNames();
        }

        return ['environment' => $this->configuration->environment, 'state' => $state];
    }

    public static function runtime(): string
    {
        return 'php ' . PHP_VERSION;
    }

    /**
     * Every installed Composer package with its version, from Composer's own runtime record of what
     * it installed (Composer\InstalledVersions, i.e. vendor/composer/installed.php). The root
     * package itself and virtual (replaced/provided) names, which have no version of their own, are
     * left out. null (and so left out of the snapshot) when the app wasn't installed with Composer 2.
     *
     * @return array<string, string>|null
     */
    public static function dependencies(): ?array
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            $root = InstalledVersions::getRootPackage()['name'] ?? null;
            $found = [];
            foreach (InstalledVersions::getAllRawData() as $installed) {
                foreach ($installed['versions'] ?? [] as $name => $package) {
                    $version = $package['pretty_version'] ?? null;
                    if ($name === $root || !is_string($version) || isset($found[$name])) {
                        continue;
                    }
                    $found[(string) $name] = $version;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        if ($found === []) {
            return null;
        }

        ksort($found, SORT_STRING);
        $dependencies = [];
        foreach (array_slice($found, 0, self::MAX_DEPENDENCIES, true) as $name => $version) {
            $dependencies[Change::truncate((string) $name, self::MAX_NAME_LENGTH)] = Change::truncate($version, self::MAX_VERSION_LENGTH);
        }

        return $dependencies;
    }

    /**
     * Names only, never values, sorted so the same set always serializes the same way.
     *
     * @param array<string, mixed>|null $env defaults to this process's environment (getenv())
     * @return string[]
     */
    public static function envVarNames(?array $env = null): array
    {
        $names = array_map('strval', array_keys($env ?? getenv()));
        $names = array_values(array_filter($names, static fn (string $name) => !self::isDeniedEnvVar($name)));
        sort($names, SORT_STRING);

        return array_slice($names, 0, self::MAX_ENV_VAR_NAMES);
    }

    public static function isDeniedEnvVar(string $name): bool
    {
        if (in_array($name, self::ENV_VAR_DENYLIST, true)) {
            return true;
        }
        foreach (self::ENV_VAR_DENYLIST_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private function markerPath(): string
    {
        $key = sha1(($this->configuration->ingestionUri() ?? '') . "\0" . ($this->configuration->apiKey() ?? '') . "\0" . $this->configuration->environment);

        return rtrim($this->markerDirectory, '/\\') . '/forge-ops-tracker-snapshot-' . $key;
    }
}
