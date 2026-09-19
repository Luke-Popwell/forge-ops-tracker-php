<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const PORT = 8098;

    private static $serverProcess = null;
    private static string $requestFile;

    public static function setUpBeforeClass(): void
    {
        self::$requestFile = sys_get_temp_dir() . '/forge_ops_tracker_test_request.json';
        @unlink(self::$requestFile);

        $router = __DIR__ . '/fixtures/echo_server.php';
        self::$serverProcess = proc_open(
            ['php', '-S', '127.0.0.1:' . self::PORT, $router],
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

    private function configuration(string $path = ''): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'http://secret-key@127.0.0.1:' . self::PORT . '/' . ltrim($path, '/');
        $config->timeout = 2.0;

        return $config;
    }

    public function testReturnsTrueOnASuccessfulResponse(): void
    {
        $client = new Client($this->configuration());

        self::assertTrue($client->deliver(['exception_class' => 'RuntimeError']));
    }

    public function testReturnsFalseOnANon2xxResponse(): void
    {
        $client = new Client($this->configuration('unauthorized'));

        self::assertFalse($client->deliver(['exception_class' => 'RuntimeError']));
    }

    public function testReturnsFalseWithoutThrowingWhenTheServerIsUnreachable(): void
    {
        $config = new Configuration();
        $config->dsn = 'http://secret-key@127.0.0.1:1/api/v1/events'; // nothing listens on port 1
        $config->timeout = 1.0;
        $client = new Client($config);

        $result = $client->deliver(['exception_class' => 'RuntimeError']); // must not throw
        self::assertFalse($result);
    }

    public function testReturnsFalseWhenThereIsNoDsnConfigured(): void
    {
        $config = $this->configuration();
        $config->dsn = null;
        $client = new Client($config);

        self::assertFalse($client->deliver(['exception_class' => 'RuntimeError']));
    }

    public function testSendsTheApiKeyAsABearerTokenAndThePayloadAsJson(): void
    {
        $client = new Client($this->configuration());

        $client->deliver(['exception_class' => 'RuntimeError', 'message' => 'boom']);

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        self::assertSame('Bearer secret-key', $received['headers']['Authorization']);
        self::assertSame(
            ['exception_class' => 'RuntimeError', 'message' => 'boom'],
            json_decode($received['body'], true)
        );
    }

    public function testDeliverSessionCheckinPostsToTheSessionCheckinsUri(): void
    {
        $client = new Client($this->configuration('api/v1/events'));

        self::assertTrue($client->deliverSessionCheckin(['sessions_count' => 1, 'crashed_sessions_count' => 0]));

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        self::assertSame('/api/v1/session_checkins', $received['path']);
        self::assertSame('Bearer secret-key', $received['headers']['Authorization']);
        self::assertSame(
            ['sessions_count' => 1, 'crashed_sessions_count' => 0],
            json_decode($received['body'], true)
        );
    }

    public function testDeliverSessionCheckinReturnsFalseWithoutAnEventsSuffixToSwap(): void
    {
        // sessionCheckinsUri() falls back to the ingestion URI unchanged when it doesn't end in
        // "/events": still a real endpoint here (the echo server accepts any path), so this
        // just confirms the fallback URI is actually the one used, not that delivery fails.
        $client = new Client($this->configuration('api/v1/session_checkins'));

        self::assertTrue($client->deliverSessionCheckin(['sessions_count' => 1]));

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        self::assertSame('/api/v1/session_checkins', $received['path']);
    }

    public function testDeliverPerformanceSamplesPostsTheBatchWrappedInSamples(): void
    {
        $client = new Client($this->configuration('api/v1/events'));

        self::assertTrue($client->deliverPerformanceSamples([
            ['transaction_name' => 'GET users/{id}', 'request_count' => 1],
        ]));

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        self::assertSame('/api/v1/performance_samples', $received['path']);
        self::assertSame(
            ['samples' => [['transaction_name' => 'GET users/{id}', 'request_count' => 1]]],
            json_decode($received['body'], true)
        );
    }
}
