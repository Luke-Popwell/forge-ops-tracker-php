<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\MetricBuffer;
use PHPUnit\Framework\TestCase;

final class MetricBufferTest extends TestCase
{
    private const PORT = 8100;

    private static $serverProcess = null;
    private static string $requestFile;

    public static function setUpBeforeClass(): void
    {
        self::$requestFile = sys_get_temp_dir() . '/forge_ops_tracker_metrics_test_requests.jsonl';
        @unlink(self::$requestFile);

        self::$serverProcess = proc_open(
            ['php', '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/fixtures/metrics_echo_server.php'],
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
        @unlink(self::$requestFile);
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    private function configuration(): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->environment = 'production';
        $config->enabledEnvironments = ['production'];
        $config->release = '1.2.3';
        $config->serverName = 'web-1';

        return $config;
    }

    public function testDeliversEveryEntryAsOneBatchStampedWithRecordedAt(): void
    {
        $delivered = [];
        $buffer = new MetricBuffer($this->configuration(), function (array $entries) use (&$delivered): bool {
            $delivered[] = $entries;

            return true;
        });

        $buffer->record(['metric_name' => 'signup', 'value' => 1.0]);
        $buffer->record(['metric_name' => 'payment', 'value' => 49.5]);
        $buffer->flush();

        self::assertCount(1, $delivered);
        self::assertSame(['signup', 'payment'], array_column($delivered[0], 'metric_name'));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $delivered[0][0]['recorded_at']);
    }

    public function testDropsNaNInfiniteAndNonNumericValuesSoOneBadEntryCannotPoisonABatch(): void
    {
        $buffer = new MetricBuffer($this->configuration(), fn (array $entries): bool => true);

        self::assertFalse($buffer->record(['metric_name' => 'a', 'value' => NAN]));
        self::assertFalse($buffer->record(['metric_name' => 'b', 'value' => INF]));
        self::assertFalse($buffer->record(['metric_name' => 'c', 'value' => '12']));
        self::assertTrue($buffer->record(['metric_name' => 'd', 'value' => -12.0])); // a refund is a real metric
    }

    public function testAFailedDeliveryKeepsEveryEntryForTheNextFlush(): void
    {
        $outcomes = [false, true];
        $delivered = [];
        $buffer = new MetricBuffer($this->configuration(), function (array $entries) use (&$outcomes, &$delivered): bool {
            $delivered[] = array_column($entries, 'metric_name');

            return array_shift($outcomes);
        });

        $buffer->record(['metric_name' => 'a', 'value' => 1.0]);
        $buffer->flush();
        $buffer->record(['metric_name' => 'b', 'value' => 2.0]);
        $buffer->flush();
        $buffer->flush(); // nothing left: no third delivery

        self::assertSame([['a'], ['a', 'b']], $delivered);
    }

    public function testAThrowingDeliveryIsSwallowedAndKeepsTheEntries(): void
    {
        $buffer = new MetricBuffer($this->configuration(), function (array $entries): bool {
            throw new \RuntimeException('boom');
        });
        $buffer->record(['metric_name' => 'a', 'value' => 1.0]);
        $buffer->flush();

        self::assertSame(1, $buffer->count());
    }

    public function testIsCappedAndDropsFurtherEntriesUntilAFlushSucceeds(): void
    {
        $buffer = new MetricBuffer($this->configuration(), fn (array $entries): bool => false);
        $accepted = 0;
        for ($i = 0; $i < MetricBuffer::MAX_ENTRIES + 50; $i++) {
            if ($buffer->record(['metric_name' => 'm', 'value' => 1.0])) {
                $accepted++;
            }
        }

        self::assertSame(MetricBuffer::MAX_ENTRIES, $accepted);
    }

    public function testCaptureMetricIsANoOpWhenTheClientIsNotEnabled(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events', environment: 'development', installExceptionHandler: false);
        ForgeOpsTracker::captureMetric('signup');
        ForgeOpsTracker::captureInfrastructureMetric('cpu', 1.0);
        ForgeOpsTracker::flushMetrics(); // returns without a buffer ever having been created

        self::assertFileDoesNotExist(self::$requestFile);
    }

    public function testMetricUrisSwapTheTrailingEventsSegment(): void
    {
        $config = $this->configuration();
        self::assertSame('https://tracker.example.com/api/v1/custom_metrics', $config->customMetricsUri());
        self::assertSame('https://tracker.example.com/api/v1/infrastructure_metrics', $config->infrastructureMetricsUri());
    }

    public function testAScriptThatCapturesReadingsAndJustEndsStillDeliversThemFromItsShutdownFunctionEndToEnd(): void
    {
        $script = sprintf(
            <<<'PHP'
            require %s;
            \ForgeOps\Tracker\ForgeOpsTracker::init(
                dsn: 'http://key@127.0.0.1:%d/api/v1/events',
                environment: 'production',
                release: 'a1b2c3d',
                enabledEnvironments: ['production'],
                serverName: 'cron-1',
                installExceptionHandler: false,
            );
            \ForgeOps\Tracker\ForgeOpsTracker::captureMetric('signup');
            \ForgeOps\Tracker\ForgeOpsTracker::captureMetric('payment', 49.0);
            \ForgeOps\Tracker\ForgeOpsTracker::captureInfrastructureMetric('cpu', 0.42);
            \ForgeOps\Tracker\ForgeOpsTracker::captureInfrastructureMetric('disk', 0.8, 'db-1');
            PHP,
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            self::PORT
        );
        $process = proc_open(['php', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);
        self::assertSame('', trim($stderr));

        $requests = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(explode("\n", (string) file_get_contents(self::$requestFile)))
        );
        $byPath = array_column($requests, null, 'path');

        $custom = $byPath['/api/v1/custom_metrics'];
        self::assertSame('Bearer key', $custom['auth']);
        self::assertSame(['signup', 'payment'], array_column($custom['body']['metrics'], 'metric_name'));
        self::assertEquals(49, $custom['body']['metrics'][1]['value']);
        self::assertSame('production', $custom['body']['metrics'][0]['environment']);
        self::assertSame('a1b2c3d', $custom['body']['metrics'][0]['release']);
        $infrastructure = $byPath['/api/v1/infrastructure_metrics']['body']['metrics'];
        self::assertSame('cron-1', $infrastructure[0]['hostname']);
        self::assertSame('db-1', $infrastructure[1]['hostname']);
    }
}
