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
}
