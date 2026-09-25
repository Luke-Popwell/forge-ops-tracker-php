<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\DeliveryQueue;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerPerformanceMiddleware;
use ForgeOps\Tracker\QueryPlanner;
use ForgeOps\Tracker\QueryPlanRateLimiter;
use ForgeOps\Tracker\SpanFlusher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Masked SQL on the Laravel integration's database spans, and the opt-in EXPLAIN it queues for a
 * slow PostgreSQL SELECT. Boots Testbench with SQLite for the real-query tests; the PostgreSQL
 * path uses a PostgresConnection whose PDO is a closure that fails the test if it's ever opened
 * (the app's own connection must never be touched), plus a fake db.factory standing in for the
 * new connection the EXPLAIN opens.
 */
final class LaravelQueryPlanIntegrationTest extends TestCase
{
    private const PLAN_JSON = '[{"Plan": {"Node Type": "Index Scan", "Index Cond": "(email = \'a@b.co\'::text)", "Total Cost": 8.3}}]';

    private string $limiterPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiterPath = tempnam(sys_get_temp_dir(), 'forge-ops-explain-test-');
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        @unlink($this->limiterPath);
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @param array<int, array<string, mixed>> $plans
     */
    private function init(array &$traces, array &$plans, bool $explain = false): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            environment: 'production',
            enabledEnvironments: ['production'],
            traceCaptureThreshold: 0.0,
            installExceptionHandler: false,
            explainSlowQueries: $explain,
            explainThresholdMs: 0.0,
        );
        $configuration = ForgeOpsTracker::configuration();

        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$traces): bool {
            $traces[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))->setValue(null, new SpanFlusher($configuration, $client));

        $queue = $this->createMock(DeliveryQueue::class);
        $queue->method('push')->willReturnCallback(function (array $payload) use (&$plans): bool {
            $plans[] = $payload;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'queryPlanner'))
            ->setValue(null, new QueryPlanner($configuration, $queue, new QueryPlanRateLimiter($configuration, $this->limiterPath)));
    }

    /**
     * A PostgresConnection that fails the test the moment anything opens its PDO.
     *
     * @param array<string, mixed> $config
     */
    private function untouchablePostgresConnection(array $config = []): PostgresConnection
    {
        $pdo = static function (): never {
            throw new RuntimeException('the app connection must never be used for the EXPLAIN');
        };

        return new PostgresConnection($pdo, 'app', '', $config + ['driver' => 'pgsql', 'name' => 'pgsql', 'host' => 'db.internal']);
    }

    private function fakeFactory(?string $failOn = null): FakeExplainConnectionFactory
    {
        $factory = new FakeExplainConnectionFactory(self::PLAN_JSON, $failOn);
        $this->app->instance('db.factory', $factory);

        return $factory;
    }

    public function testDatabaseSpanCarriesTheMaskedStatementAndDbSystem(): void
    {
        $traces = [];
        $plans = [];
        $this->init($traces, $plans);

        (new ForgeOpsTrackerPerformanceMiddleware())->handle(Request::create('/ok'), function () {
            DB::select("select 'secret@example.com' as email, ? as answer", [42]);

            return new Response('ok', 200);
        });
        ForgeOpsTracker::flushSpans();

        $database = array_values(array_filter($traces[0]['spans'], static fn (array $span): bool => $span['kind'] === 'database'));
        self::assertCount(1, $database);
        self::assertEquals((object) ['db.statement' => 'select ? as email, ? as answer', 'db.system' => 'sqlite'], $database[0]['data']);
        self::assertStringNotContainsString('secret@example.com', (string) json_encode($traces));
    }

    public function testNeverQueuesAnExplainForSqliteEvenWhenEnabled(): void
    {
        $traces = [];
        $plans = [];
        $this->init($traces, $plans, explain: true);
        $factory = $this->fakeFactory();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), function () {
            DB::select('select 1');

            return new Response('ok', 200);
        });
        $middleware->terminate(Request::create('/ok'), $response);

        self::assertSame([], $factory->made);
        self::assertSame([], $plans);
    }

    public function testSlowPostgresSelectIsExplainedAfterTheResponseOnANewReadOnlyConnection(): void
    {
        $traces = [];
        $plans = [];
        $this->init($traces, $plans, explain: true);
        $factory = $this->fakeFactory();
        $appConnection = $this->untouchablePostgresConnection();
        $sql = 'select * from users where email = ?';

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), function () use ($appConnection, $sql) {
            event(new QueryExecuted($sql, ['a@b.co'], 900.0, $appConnection));

            return new Response('ok', 200);
        });

        // Nothing runs during the request itself.
        self::assertSame([], $factory->made);

        $middleware->terminate(Request::create('/ok'), $response);

        self::assertCount(1, $factory->made);
        self::assertSame('pgsql_forge_ops_explain', $factory->made[0]['name']);
        self::assertSame('db.internal', $factory->made[0]['config']['host']);
        $fresh = $factory->made[0]['connection'];
        self::assertSame([
            ['beginTransaction'],
            ['statement', 'SET TRANSACTION READ ONLY'],
            ['statement', "SET LOCAL statement_timeout = '2s'"],
            ['select', 'EXPLAIN (FORMAT JSON) select * from users where email = ?', ['a@b.co']],
            ['rollBack'],
            ['disconnect'],
        ], $fresh->calls);

        self::assertCount(1, $plans);
        $json = (string) json_encode($plans[0]);
        self::assertStringNotContainsString('a@b.co', $json);
        self::assertSame($sql, $plans[0]['statement']);
        self::assertSame('postgresql', $plans[0]['db_system']);
        self::assertSame('(email = ?::text)', $plans[0]['plan'][0]->Plan->{'Index Cond'});

        // The span carried the same masked statement the plan does.
        ForgeOpsTracker::flushSpans();
        $database = array_values(array_filter($traces[0]['spans'], static fn (array $span): bool => $span['kind'] === 'database'));
        self::assertEquals((object) ['db.statement' => $sql, 'db.system' => 'postgresql'], $database[0]['data']);
    }

    public function testWritesAreNeverExplained(): void
    {
        $traces = [];
        $plans = [];
        $this->init($traces, $plans, explain: true);
        $factory = $this->fakeFactory();
        $appConnection = $this->untouchablePostgresConnection();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), function () use ($appConnection) {
            event(new QueryExecuted('update users set name = ? where id = ?', ['x', 1], 900.0, $appConnection));
            event(new QueryExecuted('select * from users where id = ? for update', [1], 900.0, $appConnection));

            return new Response('ok', 200);
        });
        $middleware->terminate(Request::create('/ok'), $response);

        self::assertSame([], $factory->made);
        self::assertSame([], $plans);
    }

    public function testExplainIsSkippedWhileTheAppConnectionIsInsideATransaction(): void
    {
        $factory = $this->fakeFactory();
        $appConnection = $this->createMock(PostgresConnection::class);
        $appConnection->method('transactionLevel')->willReturn(1);

        $this->expectException(RuntimeException::class);
        try {
            ForgeOpsTrackerPerformanceMiddleware::explainOnNewConnection($appConnection, 'select 1', []);
        } finally {
            self::assertSame([], $factory->made);
        }
    }

    public function testAFailedExplainStillRollsBackDisconnectsAndNeverReachesTheApp(): void
    {
        $traces = [];
        $plans = [];
        $logged = [];
        $this->init($traces, $plans, explain: true);
        ForgeOpsTracker::configuration()->logger = static function (string $message) use (&$logged): void {
            $logged[] = $message;
        };
        $factory = $this->fakeFactory(failOn: 'EXPLAIN');
        $appConnection = $this->untouchablePostgresConnection();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), function () use ($appConnection) {
            event(new QueryExecuted('select * from users', [], 900.0, $appConnection));

            return new Response('ok', 200);
        });
        $middleware->terminate(Request::create('/ok'), $response);

        $calls = $factory->made[0]['connection']->calls;
        self::assertSame(['rollBack'], $calls[count($calls) - 2]);
        self::assertSame(['disconnect'], $calls[count($calls) - 1]);
        self::assertSame([], $plans);
        self::assertStringContainsString('EXPLAIN failed', implode("\n", $logged));
    }
}

/** Stands in for Laravel's ConnectionFactory: records what it was asked to build. */
final class FakeExplainConnectionFactory
{
    /** @var list<array{config: array<string, mixed>, name: ?string, connection: FakeExplainConnection}> */
    public array $made = [];

    public function __construct(private string $plan, private ?string $failOn)
    {
    }

    /** @param array<string, mixed> $config */
    public function make(array $config, ?string $name = null): FakeExplainConnection
    {
        $connection = new FakeExplainConnection($this->plan, $this->failOn);
        $this->made[] = ['config' => $config, 'name' => $name, 'connection' => $connection];

        return $connection;
    }
}

final class FakeExplainConnection
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct(private string $plan, private ?string $failOn)
    {
    }

    public function beginTransaction(): void
    {
        $this->calls[] = ['beginTransaction'];
    }

    public function statement(string $sql): bool
    {
        $this->calls[] = ['statement', $sql];
        $this->failIfAskedTo($sql);

        return true;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<object>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $this->calls[] = ['select', $sql, $bindings];
        $this->failIfAskedTo($sql);

        return [(object) ['QUERY PLAN' => $this->plan]];
    }

    public function rollBack(): void
    {
        $this->calls[] = ['rollBack'];
    }

    public function disconnect(): void
    {
        $this->calls[] = ['disconnect'];
    }

    private function failIfAskedTo(string $sql): void
    {
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new RuntimeException('permission denied for table users');
        }
    }
}
