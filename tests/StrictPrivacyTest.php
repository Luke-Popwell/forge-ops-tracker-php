<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\BreadcrumbBuffer;
use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\EventBuilder;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\PerformanceFlusher;
use ForgeOps\Tracker\StrictPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StrictPrivacyTest extends TestCase
{
    use CapturesPayloads;

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        putenv('FORGE_OPS_STRICT_PRIVACY');
        putenv('FORGE_OPS_PRIVACY_ALLOWED_FIELDS');
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    /**
     * A byte-identical copy of sdks/strict_privacy_vectors.json, generated from the server's own
     * StrictPrivacy; every SDK checks itself against the same file.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/strict_privacy_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function fingerprintVectors(): iterable
    {
        foreach (self::vectors()['fingerprint'] as $index => $vector) {
            yield "#{$index} " . json_encode($vector['message']) => [$vector];
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function spanNameVectors(): iterable
    {
        foreach (self::vectors()['span_name'] as $vector) {
            yield $vector['name'] => [$vector];
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function pathVectors(): iterable
    {
        foreach (self::vectors()['path'] as $vector) {
            yield $vector['path'] === '' ? '(empty)' : $vector['path'] => [$vector];
        }
    }

    /** @param array<string, string> $vector */
    #[DataProvider('fingerprintVectors')]
    public function testNormalizesAndFingerprintsEveryVectorExactlyAsTheServerDoes(array $vector): void
    {
        self::assertSame($vector['normalized'], StrictPrivacy::normalizeMessage($vector['message']));
        self::assertSame($vector['fingerprint'], StrictPrivacy::messageFingerprint($vector['message']));
    }

    public function testAnAbsentMessageFingerprintsAsAnEmptyOne(): void
    {
        self::assertSame(StrictPrivacy::messageFingerprint(''), StrictPrivacy::messageFingerprint(null));
    }

    /** @param array<string, string> $vector */
    #[DataProvider('spanNameVectors')]
    public function testNamesEverySpanVectorExactlyAsTheServerDoes(array $vector): void
    {
        self::assertSame($vector['strict'], StrictPrivacy::spanName($vector['name']));
    }

    /** @param array<string, string> $vector */
    #[DataProvider('pathVectors')]
    public function testReducesEveryPathVectorExactlyAsTheServerDoes(array $vector): void
    {
        self::assertSame($vector['strict'], StrictPrivacy::strictPath($vector['path']));
    }

    public function testTransactionNameKeepsARouteAndCutsARawPathDownToItsFirstSegment(): void
    {
        self::assertSame('GET patients/{id}', StrictPrivacy::transactionName('GET patients/{id}'));
        self::assertSame('GET patients/:path', StrictPrivacy::transactionName('GET patients/8821'));
        self::assertSame('PatientController::show', StrictPrivacy::transactionName('PatientController::show'));
        self::assertNull(StrictPrivacy::transactionName(null));
    }

    public function testAnEventWithStrictPrivacyOnCutsDownARawPathTransactionName(): void
    {
        $payload = StrictPrivacy::event(['message' => 'boom', 'backtrace' => [], 'transaction_name' => 'GET patients/8821']);

        self::assertSame('GET patients/:path', $payload['transaction_name']);
    }

    /** @return iterable<string, array{bool}> */
    public static function strictOnAndOff(): iterable
    {
        yield 'strict privacy on' => [true];
        yield 'strict privacy off' => [false];
    }

    #[DataProvider('strictOnAndOff')]
    public function testPerformanceSamplesCutDownRawPathNamesOnlyWithStrictPrivacyOn(bool $strict): void
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->enabledEnvironments = ['production'];
        $config->environment = 'production';
        $config->strictPrivacy = $strict;
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverPerformanceSamples')->willReturnCallback(function (array $samples) use (&$delivered): bool {
            array_push($delivered, ...$samples);

            return true;
        });
        $flusher = new PerformanceFlusher($config, $client);

        $flusher->record('GET patients/8821', 5.0);
        $flusher->record('GET patients/{id}', 5.0);
        $flusher->flush();

        $names = array_column($delivered, 'transaction_name');
        sort($names);
        self::assertSame($strict ? ['GET patients/:path', 'GET patients/{id}'] : ['GET patients/8821', 'GET patients/{id}'], $names);
    }

    public function testIsOffWithNoAllowedFieldsByDefault(): void
    {
        $config = new Configuration();

        self::assertFalse($config->strictPrivacy);
        self::assertSame([], $config->privacyAllowedFields);
    }

    public function testReadsBothOptionsFromTheEnvironment(): void
    {
        putenv('FORGE_OPS_STRICT_PRIVACY=true');
        putenv('FORGE_OPS_PRIVACY_ALLOWED_FIELDS=error_code, feature,');

        $config = new Configuration();

        self::assertTrue($config->strictPrivacy);
        self::assertSame(['error_code', 'feature'], $config->privacyAllowedFields);
    }

    public function testAnExplicitInitOptionWinsOverTheEnvironment(): void
    {
        putenv('FORGE_OPS_STRICT_PRIVACY=true');
        putenv('FORGE_OPS_PRIVACY_ALLOWED_FIELDS=error_code');
        ForgeOpsTracker::resetForTesting();

        $config = ForgeOpsTracker::init(installExceptionHandler: false, strictPrivacy: false, privacyAllowedFields: ['feature']);

        self::assertFalse($config->strictPrivacy);
        self::assertSame(['feature'], $config->privacyAllowedFields);
    }

    public function testAnythingButATrueLikeValueIsOff(): void
    {
        putenv('FORGE_OPS_STRICT_PRIVACY=false');

        self::assertFalse((new Configuration())->strictPrivacy);
    }

    private function eventConfiguration(string $appRoot): Configuration
    {
        $config = new Configuration();
        $config->environment = 'production';
        $config->release = 'abc123';
        $config->serverName = 'web-1';
        $config->appRoot = $appRoot;
        $config->captureSqlStatement = true;

        return $config;
    }

    /** Throws, from line 10 of a file under appRoot, a database error carrying its SQL. */
    private function throwFromSourceFile(): array
    {
        $lines = [];
        for ($n = 1; $n <= 20; $n++) {
            $statement = $n === 10
                ? "throw new class(\"Couldn't find Patient with id=8821 for jane@example.com\") extends \\RuntimeException { public function getSql(): string { return 'SELECT * FROM patients WHERE id = 8821'; } };"
                : "// line {$n}";
            $lines[] = $n === 1 ? "<?php {$statement}" : $statement;
        }
        $path = tempnam(sys_get_temp_dir(), 'forge_ops_strict_') . '.php';
        $this->tempFiles[] = $path;
        file_put_contents($path, implode("\n", $lines) . "\n");

        try {
            require $path;
        } catch (RuntimeException $e) {
            return [$path, $e];
        }

        self::fail("fixture at {$path} did not throw");
    }

    /** @return array<string, mixed> */
    private function build(Configuration $config, RuntimeException $error, ?array $user = null): array
    {
        return (new EventBuilder($config))->build(
            $error,
            ['error_code' => 'E42', 'feature' => ['nested' => 'no'], 'diagnosis' => 'flu', 'tier' => 3],
            $user ?? ['id' => '7', 'email' => 'jane@example.com', 'username' => 'jane'],
            [['category' => 'custom', 'message' => "viewed Jane's chart", 'level' => 'info', 'timestamp' => 'now', 'data' => []]],
            'GET patients/{id}',
            'GET /patients/8821',
            str_repeat('a', 32),
        );
    }

    public function testAnEventWithStrictPrivacyOnCarriesOnlyStructuredFacts(): void
    {
        [$path, $error] = $this->throwFromSourceFile();
        $config = $this->eventConfiguration(dirname($path));
        $config->strictPrivacy = true;
        $config->privacyAllowedFields = ['error_code', 'feature', 'tier'];

        $payload = $this->build($config, $error);

        self::assertSame('', $payload['message']);
        // From the message as it would otherwise have been sent: after PII scrubbing.
        self::assertSame(StrictPrivacy::messageFingerprint("Couldn't find Patient with id=8821 for [EMAIL FILTERED]"), $payload['message_fingerprint']);
        foreach (['breadcrumbs', 'endpoint', 'sql_statement', 'tags'] as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
        self::assertSame(['error_code' => 'E42', 'tier' => 3], $payload['context']);
        self::assertSame(['id' => '7'], $payload['user']);
        $frame = $payload['backtrace'][0];
        self::assertSame($path, $frame['file']);
        self::assertSame(10, $frame['line']);
        foreach ($payload['backtrace'] as $each) {
            self::assertSame([], array_intersect(['context_line', 'pre_context', 'post_context'], array_keys($each)));
        }
        self::assertSame(['patients'], $payload['sql_objects']['relations']);
        self::assertSame('GET patients/{id}', $payload['transaction_name']);
        self::assertSame(str_repeat('a', 32), $payload['trace_id']);
        $json = (string) json_encode($payload);
        foreach (['jane', '8821', 'flu'] as $text) {
            self::assertStringNotContainsString($text, $json);
        }
    }

    public function testAnEventWithStrictPrivacyOnAndNoAllowedFieldsHasNoContextAtAll(): void
    {
        [$path, $error] = $this->throwFromSourceFile();
        $config = $this->eventConfiguration(dirname($path));
        $config->strictPrivacy = true;

        $payload = $this->build($config, $error);

        self::assertArrayNotHasKey('context', $payload);
        self::assertArrayNotHasKey('tags', $payload);
    }

    public function testAnEventWithStrictPrivacyOnLeavesOutAUserWithNoId(): void
    {
        [$path, $error] = $this->throwFromSourceFile();
        $config = $this->eventConfiguration(dirname($path));
        $config->strictPrivacy = true;

        self::assertArrayNotHasKey('user', $this->build($config, $error, ['email' => 'jane@example.com']));
    }

    public function testAnEventWithStrictPrivacyOffIsExactlyAsBefore(): void
    {
        [$path, $error] = $this->throwFromSourceFile();
        $config = $this->eventConfiguration(dirname($path));

        $payload = $this->build($config, $error);

        self::assertSame("Couldn't find Patient with id=8821 for [EMAIL FILTERED]", $payload['message']);
        self::assertArrayNotHasKey('message_fingerprint', $payload);
        self::assertSame("viewed Jane's chart", $payload['breadcrumbs'][0]['message']);
        self::assertSame('GET /patients/8821', $payload['endpoint']);
        self::assertSame('SELECT * FROM patients WHERE id = ?', $payload['sql_statement']);
        self::assertSame(['error_code' => 'E42', 'feature' => ['nested' => 'no'], 'diagnosis' => 'flu', 'tier' => 3], $payload['context']);
        self::assertSame(['id' => '7', 'email' => 'jane@example.com', 'username' => 'jane'], $payload['user']);
        self::assertSame([], $payload['tags']);
        self::assertArrayHasKey('context_line', $payload['backtrace'][0]);
    }

    public function testBreadcrumbsAreNeverRecordedWithStrictPrivacyOn(): void
    {
        $config = new Configuration();
        $config->strictPrivacy = true;
        $buffer = new BreadcrumbBuffer($config);

        $buffer->add('custom', "viewed Jane's chart");

        self::assertSame([], $buffer->all());
    }

    /** @return array<string, array<string, mixed>> */
    private function deliveredSpans(bool $strictPrivacy): array
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, threshold: 0.0);
        ForgeOpsTracker::configuration()->strictPrivacy = $strictPrivacy;

        ForgeOpsTracker::startTrace();
        $now = microtime(true);
        ForgeOpsTracker::recordSpan('GET https://api.example.com/patients/42?x=1', 'http', $now, 3.0, ['status' => 200]);
        ForgeOpsTracker::recordDatabaseQuery('SELECT patients', $now, 2.0, 'SELECT * FROM patients WHERE id = 8821', 'postgresql');
        ForgeOpsTracker::finishTrace('GET patients/8821', $now, 10.0);
        ForgeOpsTracker::flushSpans();

        $byKind = [];
        foreach ($traces[0]['spans'] as $span) {
            $byKind[$span['kind']] = $span;
        }

        return $byKind;
    }

    public function testSpansWithStrictPrivacyOnNameCallsByHostAndKeepOnlyStatementData(): void
    {
        $spans = $this->deliveredSpans(true);

        self::assertSame('GET api.example.com', $spans['http']['name']);
        self::assertArrayNotHasKey('data', $spans['http']);
        self::assertSame(['db.statement' => 'SELECT * FROM patients WHERE id = ?', 'db.system' => 'postgresql'], (array) $spans['database']['data']);
        self::assertSame('GET patients/:path', $spans['controller']['name']);
        self::assertArrayNotHasKey('data', $spans['controller']);
    }

    public function testSpansWithStrictPrivacyOffAreExactlyAsBefore(): void
    {
        $spans = $this->deliveredSpans(false);

        self::assertSame('GET https://api.example.com/patients/42?x=1', $spans['http']['name']);
        self::assertSame(['status' => 200], (array) $spans['http']['data']);
        self::assertSame('GET patients/8821', $spans['controller']['name']);
    }
}
