<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Change;
use ForgeOps\Tracker\ChangeSnapshot;
use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\ForgeOpsTracker;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * recordChange() and the startup change snapshot, against a real local HTTP server (the same
 * fixtures/echo_server.php ClientTest uses) wherever what goes over the wire is the point.
 */
final class ChangesTest extends TestCase
{
    private const PORT = 8101;

    private static $serverProcess = null;
    private static string $requestFile;
    private string $markerDirectory;

    public static function setUpBeforeClass(): void
    {
        self::$requestFile = sys_get_temp_dir() . '/forge_ops_tracker_test_request.json';
        @unlink(self::$requestFile);

        self::$serverProcess = proc_open(
            ['php', '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/fixtures/echo_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        self::fail('local test server did not start listening on port ' . self::PORT);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
        @unlink(self::$requestFile);
    }

    protected function setUp(): void
    {
        ForgeOpsTracker::resetForTesting();
        @unlink(self::$requestFile);
        $this->markerDirectory = sys_get_temp_dir() . '/forge-ops-tracker-changes-test-' . bin2hex(random_bytes(6));
        mkdir($this->markerDirectory);
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        foreach (glob($this->markerDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->markerDirectory);
    }

    private function configuration(string $path = 'api/v1/events'): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'http://secret-key@127.0.0.1:' . self::PORT . '/' . $path;
        $config->environment = 'production';
        $config->timeout = 2.0;

        return $config;
    }

    /** @return array{path: string, headers: array<string, string>, body: array<string, mixed>}|null */
    private function lastRequest(): ?array
    {
        $raw = @file_get_contents(self::$requestFile);
        if ($raw === false) {
            return null;
        }
        $received = json_decode($raw, true);
        $received['body'] = json_decode($received['body'], true);

        return $received;
    }

    private function initEnabled(string $path = 'api/v1/events'): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://secret-key@127.0.0.1:' . self::PORT . '/' . $path,
            environment: 'production',
            enabledEnvironments: ['production'],
            installExceptionHandler: false,
        );
    }

    // recordChange

    public function testRecordChangePostsTheFullPayloadToTheChangesEndpoint(): void
    {
        $this->initEnabled();

        $queued = ForgeOpsTracker::recordChange(
            'feature_flag',
            'Enabled new_checkout for 10%',
            ['flag' => 'new_checkout', 'rollout' => 10],
            service: 'web',
            actor: 'luke',
            url: 'https://flags.example.com/new_checkout',
            id: 'flag-42',
            occurredAt: new \DateTimeImmutable('2026-09-25T14:00:00+02:00'),
        );
        self::assertTrue($queued);
        self::assertNull($this->lastRequest()); // not sent until shutdown (or an explicit flush)

        ForgeOpsTracker::flushChanges();

        $request = $this->lastRequest();
        self::assertSame('/api/v1/changes', $request['path']);
        self::assertSame('Bearer secret-key', $request['headers']['Authorization']);
        self::assertSame([
            'kind' => 'feature_flag',
            'title' => 'Enabled new_checkout for 10%',
            'details' => ['flag' => 'new_checkout', 'rollout' => 10],
            'environment' => 'production',
            'service' => 'web',
            'actor' => 'luke',
            'url' => 'https://flags.example.com/new_checkout',
            'id' => 'flag-42',
            'occurred_at' => '2026-09-25T12:00:00.000Z',
        ], $request['body']);
    }

    public function testOptionalFieldsAreLeftOutAndDetailsIsAlwaysAJsonObject(): void
    {
        $payload = Change::build($this->configuration(), 'config', 'Raised the timeout');

        self::assertSame(['kind', 'title', 'details', 'environment', 'occurred_at'], array_keys($payload));
        self::assertSame('{}', json_encode($payload['details']));
        self::assertSame('{}', json_encode(Change::build($this->configuration(), 'config', 'x', ['a', 'b'])['details']));
        self::assertEqualsWithDelta(time(), strtotime($payload['occurred_at']), 5);
    }

    public function testAnExplicitEnvironmentOverridesTheConfiguredOne(): void
    {
        self::assertSame('staging', Change::build($this->configuration(), 'config', 'x', environment: 'staging')['environment']);
    }

    public function testEveryKnownKindIsSentAsIsAndAnythingElseAsOther(): void
    {
        foreach (Change::KINDS as $kind) {
            self::assertSame($kind, Change::build($this->configuration(), $kind, 'x')['kind']);
        }
        foreach (['deploy', '', 'FEATURE_FLAG'] as $kind) {
            self::assertSame('other', Change::build($this->configuration(), $kind, 'x')['kind']);
        }
    }

    public function testTheTitleIsTruncatedTo200CharactersWithoutSplittingOne(): void
    {
        self::assertSame(str_repeat('x', 200), Change::build($this->configuration(), 'config', str_repeat('x', 300))['title']);
        self::assertSame(str_repeat('é', 200), Change::build($this->configuration(), 'config', str_repeat('é', 300))['title']);
    }

    public function testABlankTitleSendsNothing(): void
    {
        $this->initEnabled();

        self::assertFalse(ForgeOpsTracker::recordChange('config', '   '));
        ForgeOpsTracker::flushChanges();
        self::assertNull($this->lastRequest());
    }

    public function testRecordChangeIsANoOpWhenTheClientIsNotEnabled(): void
    {
        ForgeOpsTracker::init(dsn: 'http://secret-key@127.0.0.1:' . self::PORT . '/api/v1/events', environment: 'development', installExceptionHandler: false);

        self::assertFalse(ForgeOpsTracker::recordChange('config', 'Raised the timeout'));
        ForgeOpsTracker::flushChanges();
        self::assertNull($this->lastRequest());
    }

    public function testRecordChangeNeverThrowsOnAnErrorResponseOrAnUnreachableServer(): void
    {
        $this->initEnabled('unauthorized/events');
        self::assertTrue(ForgeOpsTracker::recordChange('config', 'x'));
        ForgeOpsTracker::flushChanges(); // answered 401: must not throw
        self::assertSame('/unauthorized/changes', $this->lastRequest()['path']);

        ForgeOpsTracker::resetForTesting();
        ForgeOpsTracker::init(dsn: 'http://key@127.0.0.1:1/api/v1/events', environment: 'production', timeout: 1.0, installExceptionHandler: false);
        self::assertTrue(ForgeOpsTracker::recordChange('config', 'x'));
        ForgeOpsTracker::flushChanges(); // nothing listens on port 1: must not throw
        $this->addToAssertionCount(1);
    }

    // Startup snapshot

    public function testTheSnapshotHasRuntimeAndComposerDependenciesButNoEnvVarNamesByDefault(): void
    {
        $payload = (new ChangeSnapshot($this->configuration(), new Client($this->configuration()), $this->markerDirectory))->payload();

        self::assertSame('production', $payload['environment']);
        self::assertSame('php ' . PHP_VERSION, $payload['state']['runtime']);
        self::assertArrayHasKey('phpunit/phpunit', $payload['state']['dependencies']);
        self::assertArrayNotHasKey('forge-ops/tracker', $payload['state']['dependencies']); // the root package itself
        foreach ($payload['state']['dependencies'] as $name => $version) {
            self::assertIsString($name);
            self::assertIsString($version);
        }
        self::assertArrayNotHasKey('env_var_names', $payload['state']);
        self::assertArrayNotHasKey('schema_version', $payload['state']);
    }

    public function testEnvVarNamesNeverValuesAreSentWhenOptedIn(): void
    {
        $config = $this->configuration();
        $config->trackEnvVarNames = true;
        putenv('CHANGES_TEST_STRIPE_KEY=sk_live_value');
        putenv('FORGE_OPS_CHANGES_TEST=ignored');
        try {
            $state = (new ChangeSnapshot($config, new Client($config), $this->markerDirectory))->payload()['state'];
        } finally {
            putenv('CHANGES_TEST_STRIPE_KEY');
            putenv('FORGE_OPS_CHANGES_TEST');
        }

        self::assertContains('CHANGES_TEST_STRIPE_KEY', $state['env_var_names']);
        self::assertNotContains('FORGE_OPS_CHANGES_TEST', $state['env_var_names']);
        self::assertStringNotContainsString('sk_live_value', (string) json_encode($state));
    }

    public function testTheEnvVarDenylistDropsHostSpecificNoiseAndTheClientsOwnSettings(): void
    {
        $names = [
            'HOSTNAME', 'HOST', 'HOME', 'PATH', 'PWD', 'OLDPWD', 'SHLVL', '_', 'TERM', 'USER', 'LOGNAME', 'SHELL',
            'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'TZ', 'PORT', 'DYNO', 'INVOCATION_ID', 'JOURNAL_STREAM',
            'SYSTEMD_EXEC_PID', 'MEMORY_PRESSURE_WATCH', 'KUBERNETES_SERVICE_HOST', 'REDIS_SERVICE_HOST',
            'REDIS_SERVICE_PORT', 'REDIS_SERVICE_PORT_HTTP', 'REDIS_PORT_6379_TCP', 'REDIS_PORT_6379_TCP_ADDR',
            'FORGE_OPS_DSN', 'FORGE_OPS_RELEASE', 'APP_KEY', 'DB_PASSWORD', 'APP_ENV',
        ];

        self::assertSame(['APP_ENV', 'APP_KEY', 'DB_PASSWORD'], ChangeSnapshot::envVarNames(array_fill_keys($names, 'value')));
    }

    public function testInitSendsOneSnapshotToTheChangeSnapshotsEndpointAtShutdown(): void
    {
        ForgeOpsTracker::resetForTesting(changeSnapshot: true);
        $this->initEnabled();
        $this->useMarkerDirectory();
        $this->initEnabled(); // a second init() in the same process schedules nothing more

        self::assertNull($this->lastRequest()); // nothing sent during init()
        ForgeOpsTracker::flushChanges(); // what the shutdown function does

        $request = $this->lastRequest();
        self::assertSame('/api/v1/change_snapshots', $request['path']);
        self::assertSame('Bearer secret-key', $request['headers']['Authorization']);
        self::assertSame('production', $request['body']['environment']);
        self::assertSame('php ' . PHP_VERSION, $request['body']['state']['runtime']);

        @unlink(self::$requestFile);
        ForgeOpsTracker::flushChanges();
        self::assertNull($this->lastRequest()); // once per process
    }

    public function testTheSameSnapshotIsNotSentAgainFromTheSameHostButAChangedOneIs(): void
    {
        $config = $this->configuration();
        $snapshot = new ChangeSnapshot($config, new Client($config), $this->markerDirectory);

        self::assertTrue($snapshot->send());
        @unlink(self::$requestFile);

        // What a fresh PHP-FPM request (fresh statics, same deploy) does: nothing goes out.
        self::assertFalse((new ChangeSnapshot($config, new Client($config), $this->markerDirectory))->send());
        self::assertNull($this->lastRequest());

        // A deploy that changed something: sent again.
        $config->trackEnvVarNames = true;
        self::assertTrue((new ChangeSnapshot($config, new Client($config), $this->markerDirectory))->send());
        self::assertSame('/api/v1/change_snapshots', $this->lastRequest()['path']);
    }

    public function testNothingIsSentWhenTheMarkerCannotBeWritten(): void
    {
        $config = $this->configuration();
        $snapshot = new ChangeSnapshot($config, new Client($config), $this->markerDirectory . '/does-not-exist');

        self::assertFalse($snapshot->send());
        self::assertNull($this->lastRequest());
    }

    public function testDetectChangesFalseSendsNoSnapshot(): void
    {
        ForgeOpsTracker::resetForTesting(changeSnapshot: true);
        ForgeOpsTracker::init(
            dsn: 'http://secret-key@127.0.0.1:' . self::PORT . '/api/v1/events',
            environment: 'production',
            enabledEnvironments: ['production'],
            installExceptionHandler: false,
            detectChanges: false,
        );

        self::assertNull((new ReflectionProperty(ForgeOpsTracker::class, 'pendingChangeSnapshot'))->getValue());
        ForgeOpsTracker::flushChanges();
        self::assertNull($this->lastRequest());
    }

    public function testNoSnapshotIsScheduledWhenTheClientIsNotEnabled(): void
    {
        ForgeOpsTracker::resetForTesting(changeSnapshot: true);
        ForgeOpsTracker::init(dsn: 'http://secret-key@127.0.0.1:' . self::PORT . '/api/v1/events', environment: 'development', installExceptionHandler: false);

        self::assertNull((new ReflectionProperty(ForgeOpsTracker::class, 'pendingChangeSnapshot'))->getValue());
    }

    public function testAFailingSnapshotNeverThrows(): void
    {
        $config = new Configuration();
        $config->dsn = 'http://key@127.0.0.1:1/api/v1/events'; // nothing listens on port 1
        $config->environment = 'production';
        $config->timeout = 1.0;

        self::assertFalse((new ChangeSnapshot($config, new Client($config), $this->markerDirectory))->send());
    }

    /** Points the snapshot init() just scheduled at this test's own marker directory. */
    private function useMarkerDirectory(): void
    {
        $property = new ReflectionProperty(ForgeOpsTracker::class, 'pendingChangeSnapshot');
        $configuration = ForgeOpsTracker::configuration();
        $property->setValue(null, new ChangeSnapshot($configuration, new Client($configuration), $this->markerDirectory));
    }
}
