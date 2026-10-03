<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\ForgeOpsTracker;
use PHPUnit\Framework\TestCase;

/**
 * The environment default, the one-time "Not sending" warning, and what an uncaught exception does
 * to the process, including end-to-end runs of a fresh `php` against a local HTTP server, the way a
 * new customer's script would run.
 */
final class EnvironmentDefaultTest extends TestCase
{
    private const PORT = 8102;
    private const DSN = 'https://key@tracker.example.com/api/v1/events';
    private const VARIABLES = ['FORGE_OPS_ENVIRONMENT', 'APP_ENV'];

    private static $serverProcess = null;
    private static string $recordFile;

    /** @var array<string, string|false> */
    private array $savedVariables = [];

    public static function setUpBeforeClass(): void
    {
        self::$recordFile = sys_get_temp_dir() . '/forge_ops_tracker_environment_test_' . getmypid() . '.jsonl';
        @unlink(self::$recordFile);

        self::$serverProcess = proc_open(
            ['php', '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/fixtures/recording_server.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['RECORDING_SERVER_FILE' => self::$recordFile]),
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
        @unlink(self::$recordFile);
    }

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->savedVariables[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        @unlink(self::$recordFile);
        ForgeOpsTracker::resetForTesting();
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        foreach ($this->savedVariables as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testEnvironmentDefaultsToProductionWhenNothingIsSet(): void
    {
        self::assertSame('production', (new Configuration())->environment);
    }

    public function testForgeOpsEnvironmentWins(): void
    {
        putenv('FORGE_OPS_ENVIRONMENT=staging');
        putenv('APP_ENV=local');

        self::assertSame('staging', (new Configuration())->environment);
    }

    public function testAppEnvIsUsedWhenForgeOpsEnvironmentIsUnset(): void
    {
        putenv('APP_ENV=local');

        self::assertSame('local', (new Configuration())->environment);
    }

    public function testSymfonysShortNamesAreReadAsTheFullOnes(): void
    {
        putenv('APP_ENV=prod');
        self::assertSame('production', (new Configuration())->environment);

        putenv('APP_ENV=dev');
        self::assertSame('development', (new Configuration())->environment);
    }

    public function testAppEnvSetOnlyInEnvSuperglobalIsRead(): void
    {
        $_ENV['APP_ENV'] = 'local';

        self::assertSame('local', (new Configuration())->environment);
    }

    public function testEnabledEnvironmentsDefaultIsUnchanged(): void
    {
        self::assertSame(['production', 'staging'], (new Configuration())->enabledEnvironments);
    }

    public function testInitWarnsOnceWhenTheEnvironmentIsNotEnabled(): void
    {
        $messages = [];
        $logger = static function (string $message) use (&$messages): void {
            $messages[] = $message;
        };

        ForgeOpsTracker::init(dsn: self::DSN, environment: 'development', logger: $logger, installExceptionHandler: false);
        ForgeOpsTracker::init(dsn: self::DSN, environment: 'development', logger: $logger, installExceptionHandler: false);

        self::assertSame([
            '[ForgeOps] Not sending: this environment is "development", and only production, staging are enabled. '
            . 'Set FORGE_OPS_ENVIRONMENT=production (or add "development" to enabledEnvironments) to send from here.',
        ], $messages);
    }

    public function testInitDoesNotWarnWithoutADsn(): void
    {
        $messages = [];
        ForgeOpsTracker::init(
            environment: 'development',
            logger: static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            installExceptionHandler: false,
        );

        self::assertSame([], $messages);
    }

    public function testInitDoesNotWarnInAnEnabledEnvironment(): void
    {
        $messages = [];
        ForgeOpsTracker::init(
            dsn: self::DSN,
            environment: 'staging',
            logger: static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            installExceptionHandler: false,
        );

        self::assertSame([], $messages);
    }

    public function testAChildProcessWithOnlyADsnDeliversItsUncaughtErrorAndStillDiesLikePhp(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runChild(<<<'PHP'
            ForgeOpsTracker::init();
            throw new \RuntimeException('first error from a new customer');
            PHP);

        self::assertSame(255, $exitCode, $stderr);
        self::assertStringContainsString('PHP Fatal error:  Uncaught RuntimeException: first error from a new customer', $stderr);
        self::assertStringContainsString('  thrown in ', $stderr);
        self::assertStringNotContainsString('[ForgeOps]', $stderr);

        $events = $this->events();
        self::assertSame(['first error from a new customer'], array_column($events, 'message'));
        self::assertSame('production', $events[0]['environment']);
    }

    public function testAChildProcessWithAPreviousHandlerStillHandsItTheException(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runChild(<<<'PHP'
            set_exception_handler(static function (\Throwable $e): void {
                fwrite(STDERR, 'previous handler saw: ' . $e->getMessage() . "\n");
            });
            ForgeOpsTracker::init();
            throw new \RuntimeException('handled elsewhere');
            PHP);

        // The app's own handler decides how the process ends; PHP exits 0 after one returns.
        self::assertSame(0, $exitCode, $stderr);
        self::assertStringContainsString('previous handler saw: handled elsewhere', $stderr);
        self::assertStringNotContainsString('PHP Fatal error', $stderr);
        self::assertSame(['handled elsewhere'], array_column($this->events(), 'message'));
    }

    public function testAChildProcessInDevelopmentSendsNothingAndWarnsOnce(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runChild(<<<'PHP'
            ForgeOpsTracker::init();
            ForgeOpsTracker::init();
            throw new \RuntimeException('first error from a new customer');
            PHP, ['FORGE_OPS_ENVIRONMENT' => 'development']);

        self::assertSame(255, $exitCode, $stderr);
        self::assertStringContainsString('PHP Fatal error:  Uncaught RuntimeException', $stderr);
        self::assertSame(1, substr_count($stderr, '[ForgeOps] Not sending'), $stderr);
        self::assertStringContainsString('this environment is "development"', $stderr);
        self::assertFileDoesNotExist(self::$recordFile);
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{int, string, string}
     */
    private function runChild(string $body, array $extraEnv = []): array
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'forge_ops_child_');
        $script = $base . '.php';
        file_put_contents($script, "<?php\nrequire " . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
            . ";\nuse ForgeOps\\Tracker\\ForgeOpsTracker;\n" . $body . "\n");

        $env = array_filter(
            getenv(),
            static fn (string $name): bool => !str_starts_with($name, 'FORGE_OPS_') && $name !== 'APP_ENV',
            ARRAY_FILTER_USE_KEY,
        );
        $env['FORGE_OPS_DSN'] = 'http://key@127.0.0.1:' . self::PORT . '/api/v1/events';
        $env = array_merge($env, $extraEnv);

        // log_errors to stderr and display_errors off pin PHP's own fatal error output to one place,
        // whatever this machine's php.ini says; detectChanges is left on, its snapshot goes to a
        // different path than events.
        $process = proc_open(
            ['php', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
        @unlink($script);
        @unlink($base);

        return [$exitCode, (string) $stdout, (string) $stderr];
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        if (!file_exists(self::$recordFile)) {
            return [];
        }

        $events = [];
        foreach (file(self::$recordFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $request = json_decode($line, true);
            if ($request['path'] === '/api/v1/events') {
                $events[] = json_decode($request['body'], true);
            }
        }

        return $events;
    }
}
