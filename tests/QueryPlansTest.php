<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\DeliveryQueue;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\QueryPlanner;
use ForgeOps\Tracker\QueryPlanRateLimiter;
use ForgeOps\Tracker\QueryPlans;
use ForgeOps\Tracker\SpanFlusher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class QueryPlansTest extends TestCase
{
    private const PLAN_JSON = '[{"Plan": {"Node Type": "Seq Scan", "Relation Name": "users", "Filter": "((email = \'a@b.co\'::text) AND (id > 42))", "Total Cost": 35.5, "Plan Rows": 1}}]';

    private string $limiterPath;
    private float $now = 1000.0;

    protected function setUp(): void
    {
        $this->limiterPath = tempnam(sys_get_temp_dir(), 'forge-ops-explain-test-');
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        @unlink($this->limiterPath);
    }

    private function configuration(bool $explain = true, float $threshold = 500.0, string $environment = 'production'): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->enabledEnvironments = ['production'];
        $config->environment = $environment;
        $config->explainSlowQueries = $explain;
        $config->explainThresholdMs = $threshold;

        return $config;
    }

    private function limiter(Configuration $configuration): QueryPlanRateLimiter
    {
        return new QueryPlanRateLimiter($configuration, $this->limiterPath, fn (): float => $this->now);
    }

    /** @param array<int, array<string, mixed>> $delivered */
    private function planner(Configuration $configuration, array &$delivered): QueryPlanner
    {
        $queue = $this->createMock(DeliveryQueue::class);
        $queue->method('push')->willReturnCallback(function (array $payload) use (&$delivered): bool {
            $delivered[] = $payload;

            return true;
        });

        return new QueryPlanner($configuration, $queue, $this->limiter($configuration));
    }

    /** @return array<string, array{0: string}> */
    public static function explainableStatements(): array
    {
        return [
            'plain' => ['SELECT 1'],
            'lowercase with leading space' => ['  select * from users where id = ?'],
            'trailing semicolon' => ['SELECT * FROM users;'],
            'leading line comment' => ["-- a leading comment\nSELECT * FROM users"],
            'leading block comment' => ['/* app:web */ SELECT * FROM users'],
            'keywords inside a string' => ["SELECT * FROM users WHERE note = 'delete; update'"],
            'deleted_at column' => ['SELECT * FROM users WHERE deleted_at IS NULL'],
        ];
    }

    #[DataProvider('explainableStatements')]
    public function testExplainableAcceptsPlainSelects(string $statement): void
    {
        self::assertTrue(QueryPlans::explainable($statement));
    }

    /** @return array<string, array{0: ?string}> */
    public static function unexplainableStatements(): array
    {
        return [
            'null' => [null],
            'blank' => ['   '],
            'insert' => ['INSERT INTO users (email) VALUES (?)'],
            'update' => ['UPDATE users SET email = ?'],
            'delete' => ['DELETE FROM users'],
            'data-modifying CTE' => ['WITH gone AS (DELETE FROM users RETURNING id) SELECT * FROM gone'],
            'plain CTE' => ['WITH x AS (SELECT 1) SELECT * FROM x'],
            'for update' => ['SELECT * FROM users FOR UPDATE'],
            'for share' => ['SELECT * FROM users for share'],
            'for no key update' => ['SELECT * FROM users FOR NO KEY UPDATE'],
            'for key share' => ['SELECT * FROM users FOR KEY SHARE'],
            'second statement' => ['SELECT 1; SELECT 2'],
            'merge' => ['SELECT * FROM a MERGE'],
            'explain' => ['EXPLAIN SELECT 1'],
            'comment hiding a delete' => ["-- SELECT\nDELETE FROM users"],
            'quote inside a comment' => ["SELECT 1 -- it's fine\n; DELETE FROM users; --'"],
        ];
    }

    #[DataProvider('unexplainableStatements')]
    public function testExplainableRejectsAnythingButASinglePlainSelect(?string $statement): void
    {
        self::assertFalse(QueryPlans::explainable($statement));
    }

    public function testDbSystemNamesLaravelDrivers(): void
    {
        self::assertSame('postgresql', QueryPlans::dbSystem('pgsql'));
        self::assertSame('mssql', QueryPlans::dbSystem('sqlsrv'));
        self::assertSame('mysql', QueryPlans::dbSystem('mysql'));
        self::assertSame('sqlite', QueryPlans::dbSystem('SQLite'));
        self::assertNull(QueryPlans::dbSystem(null));
        self::assertNull(QueryPlans::dbSystem(''));
    }

    public function testMaskPlanMasksEveryStringAndLeavesNumbersKeysAndStructureAlone(): void
    {
        $masked = QueryPlans::maskPlan(json_decode(self::PLAN_JSON));
        $node = $masked[0]->Plan;

        self::assertSame('((email = ?::text) AND (id > ?))', $node->Filter);
        self::assertSame('Seq Scan', $node->{'Node Type'});
        self::assertSame('users', $node->{'Relation Name'});
        self::assertSame(35.5, $node->{'Total Cost'});
        self::assertSame(1, $node->{'Plan Rows'});
        self::assertStringNotContainsString('a@b.co', (string) json_encode($masked));
    }

    public function testPayloadCarriesTheMaskedStatementAndPlan(): void
    {
        $payload = QueryPlans::payload($this->configuration(), 'SELECT ?', 'postgresql', self::PLAN_JSON, 812.5, 1790294400.0);

        self::assertNotNull($payload);
        self::assertSame('SELECT ?', $payload['statement']);
        self::assertSame('postgresql', $payload['db_system']);
        self::assertSame(812.5, $payload['duration_ms']);
        self::assertSame('production', $payload['environment']);
        self::assertSame('2026-09-25T00:00:00.000Z', $payload['captured_at']);
        self::assertSame('((email = ?::text) AND (id > ?))', $payload['plan'][0]->Plan->Filter);
    }

    public function testPayloadDropsAPlanOver64Kb(): void
    {
        $huge = [['Plan' => ['Node Type' => 'Seq Scan', 'Output' => array_fill(0, 70, str_repeat('x', 1000))]]];

        self::assertNull(QueryPlans::payload($this->configuration(), 'SELECT ?', 'postgresql', $huge, 900, microtime(true)));
    }

    public function testPayloadDropsSomethingThatIsNotAPlan(): void
    {
        self::assertNull(QueryPlans::payload($this->configuration(), 'SELECT ?', 'postgresql', 'not json', 900, microtime(true)));
        self::assertNull(QueryPlans::payload($this->configuration(), 'SELECT ?', 'postgresql', null, 900, microtime(true)));
    }

    public function testRateLimiterAllowsEachStatementOncePerTenMinutes(): void
    {
        $limiter = $this->limiter($this->configuration());

        self::assertTrue($limiter->allow('SELECT ?'));
        self::assertFalse($limiter->allow('SELECT ?'));
        $this->now += 599;
        self::assertFalse($limiter->allow('SELECT ?'));
        $this->now += 1;
        self::assertTrue($limiter->allow('SELECT ?'));
    }

    public function testRateLimiterAllowsAtMostTenPerMinuteOverall(): void
    {
        $limiter = $this->limiter($this->configuration());

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($limiter->allow("SELECT {$i}"));
        }
        self::assertFalse($limiter->allow('SELECT 10'));
        $this->now += 60;
        self::assertTrue($limiter->allow('SELECT 10'));
    }

    public function testRateLimitSurvivesANewLimiterInstanceTheWayANewFpmRequestWouldSeeIt(): void
    {
        self::assertTrue($this->limiter($this->configuration())->allow('SELECT ?'));
        self::assertFalse($this->limiter($this->configuration())->allow('SELECT ?'));
    }

    public function testRateLimiterAllowsNothingWhenItsStateFileCannotBeOpened(): void
    {
        $limiter = new QueryPlanRateLimiter($this->configuration(), '/nonexistent-dir/forge-ops/explain.json');

        self::assertFalse($limiter->allow('SELECT ?'));
    }

    public function testExplainIsOffByDefault(): void
    {
        $delivered = [];
        $config = $this->configuration();
        $config->explainSlowQueries = (new Configuration())->explainSlowQueries;
        $planner = $this->planner($config, $delivered);

        self::assertFalse((new Configuration())->explainSlowQueries);
        self::assertSame(500.0, (new Configuration())->explainThresholdMs);
        self::assertFalse($planner->consider('SELECT * FROM users', 'postgresql', 5000, static fn () => self::PLAN_JSON));
    }

    public function testExplainSkipsFastQueriesOtherDatabasesAndNonSelects(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(), $delivered);
        $plan = static fn () => self::PLAN_JSON;

        self::assertFalse($planner->consider('SELECT * FROM users', 'postgresql', 499.9, $plan));
        self::assertFalse($planner->consider('SELECT * FROM users', 'mysql', 5000, $plan));
        self::assertFalse($planner->consider('SELECT * FROM users', null, 5000, $plan));
        self::assertFalse($planner->consider('UPDATE users SET email = ?', 'postgresql', 5000, $plan));
        self::assertFalse($planner->consider('SELECT * FROM users FOR UPDATE', 'postgresql', 5000, $plan));
        self::assertTrue($planner->consider('SELECT * FROM users', 'postgresql', 500, $plan));
    }

    public function testExplainIsSkippedWhenTheClientIsNotEnabled(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(environment: 'development'), $delivered);

        self::assertFalse($planner->consider('SELECT * FROM users', 'postgresql', 5000, static fn () => self::PLAN_JSON));
    }

    public function testExplainRateLimitsRepeatsOfTheSameMaskedStatement(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(), $delivered);
        $plan = static fn () => self::PLAN_JSON;

        self::assertTrue($planner->consider('SELECT * FROM users WHERE id = 1', 'postgresql', 900, $plan));
        self::assertFalse($planner->consider('SELECT * FROM users WHERE id = 2', 'postgresql', 900, $plan));
        $this->now += 600;
        self::assertTrue($planner->consider('SELECT * FROM users WHERE id = 3', 'postgresql', 900, $plan));
    }

    public function testNothingRunsUntilRunPendingAndThenOnlyMaskedDataIsQueued(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(), $delivered);
        $ran = 0;

        $planner->consider("SELECT * FROM users WHERE email = 'a@b.co' AND id > 42", 'postgresql', 812.5, function () use (&$ran): string {
            $ran++;

            return self::PLAN_JSON;
        });

        self::assertSame(0, $ran);
        self::assertSame(1, $planner->pendingCount());

        $planner->runPending();

        self::assertSame(1, $ran);
        self::assertSame(0, $planner->pendingCount());
        self::assertCount(1, $delivered);
        $json = (string) json_encode($delivered[0]);
        self::assertStringNotContainsString('a@b.co', $json);
        self::assertSame('SELECT * FROM users WHERE email = ? AND id > ?', $delivered[0]['statement']);
        self::assertSame('((email = ?::text) AND (id > ?))', $delivered[0]['plan'][0]->Plan->Filter);

        $planner->runPending();
        self::assertSame(1, $ran);
    }

    public function testExplainFailuresAreSwallowedAndLogged(): void
    {
        $logged = [];
        $config = $this->configuration();
        $config->logger = static function (string $message) use (&$logged): void {
            $logged[] = $message;
        };
        $delivered = [];
        $planner = $this->planner($config, $delivered);

        $planner->consider('SELECT * FROM users', 'postgresql', 900, static function (): never {
            throw new RuntimeException('canceling statement due to statement timeout');
        });
        $planner->runPending();

        self::assertSame([], $delivered);
        self::assertStringContainsString('EXPLAIN failed', implode("\n", $logged));
        self::assertFalse(QueryPlanner::isExplaining());
    }

    public function testIsExplainingIsOnlyTrueWhileTheExplainRuns(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(), $delivered);
        $seen = [];

        $planner->consider('SELECT * FROM users', 'postgresql', 900, static function () use (&$seen): string {
            $seen[] = QueryPlanner::isExplaining();

            return self::PLAN_JSON;
        });
        $planner->runPending();

        self::assertSame([true], $seen);
        self::assertFalse(QueryPlanner::isExplaining());
    }

    public function testPendingIsCappedWithoutThrowing(): void
    {
        $delivered = [];
        $planner = $this->planner($this->configuration(), $delivered);
        $plan = static fn () => self::PLAN_JSON;

        for ($i = 0; $i < QueryPlanner::MAX_PENDING; $i++) {
            $this->now += 1000;
            self::assertTrue($planner->consider("SELECT * FROM t{$i}", 'postgresql', 900, $plan));
        }
        self::assertFalse($planner->consider('SELECT * FROM overflow', 'postgresql', 900, $plan));
    }

    public function testQueryPlansUriSwapsTheTrailingEventsSegment(): void
    {
        self::assertSame('https://tracker.example.com/api/v1/query_plans', $this->configuration()->queryPlansUri());
    }

    // Database spans

    /** @param array<int, array<string, mixed>> $traces */
    private function initCapturingTraces(array &$traces): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            environment: 'production',
            enabledEnvironments: ['production'],
            traceCaptureThreshold: 0.0,
            installExceptionHandler: false,
        );
        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$traces): bool {
            $traces[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))
            ->setValue(null, new SpanFlusher(ForgeOpsTracker::configuration(), $client));
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @return array<string, mixed>
     */
    private function onlyChildSpan(array &$traces): array
    {
        ForgeOpsTracker::flushSpans();
        self::assertCount(1, $traces);
        self::assertCount(2, $traces[0]['spans']);

        return $traces[0]['spans'][1];
    }

    public function testRecordDatabaseQueryPutsTheMaskedStatementAndDbSystemOnTheSpan(): void
    {
        $traces = [];
        $this->initCapturingTraces($traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::recordDatabaseQuery('SELECT users', microtime(true), 3.0, "SELECT * FROM users WHERE email = 'a@b.co' AND id = 42", 'pgsql');
        ForgeOpsTracker::finishTrace('GET /', microtime(true), 10);

        $span = $this->onlyChildSpan($traces);
        self::assertSame('database', $span['kind']);
        self::assertEquals((object) ['db.statement' => 'SELECT * FROM users WHERE email = ? AND id = ?', 'db.system' => 'postgresql'], $span['data']);
        self::assertStringNotContainsString('a@b.co', (string) json_encode($traces));
    }

    public function testRecordDatabaseQueryCapsTheStatementAt4000Characters(): void
    {
        $traces = [];
        $this->initCapturingTraces($traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::recordDatabaseQuery('SELECT', microtime(true), 1.0, 'SELECT ' . str_repeat('a, ', 3000) . 'b');
        ForgeOpsTracker::finishTrace('GET /', microtime(true), 10);

        $data = (array) $this->onlyChildSpan($traces)['data'];
        self::assertSame(4000, strlen($data['db.statement']));
        self::assertArrayNotHasKey('db.system', $data);
    }

    public function testRecordDatabaseQueryNeverThrowsWhenTheExplainIsBroken(): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            environment: 'production',
            enabledEnvironments: ['production'],
            installExceptionHandler: false,
            explainSlowQueries: true,
            explainThresholdMs: 0.0,
        );
        $delivered = [];
        (new ReflectionProperty(ForgeOpsTracker::class, 'queryPlanner'))->setValue(null, $this->planner(ForgeOpsTracker::configuration(), $delivered));

        ForgeOpsTracker::recordDatabaseQuery('SELECT', microtime(true), 900, 'SELECT * FROM users', 'pgsql', static function (): never {
            throw new RuntimeException('boom');
        });
        ForgeOpsTracker::runQueryPlans();

        self::assertSame([], $delivered);
    }

    public function testManualDatabaseSpanTakesAStatementAndMasksIt(): void
    {
        $traces = [];
        $this->initCapturingTraces($traces);

        ForgeOpsTracker::startTrace();
        $result = ForgeOpsTracker::span('Load orders', static fn (): int => 3, 'database', statement: 'SELECT * FROM orders WHERE total > 100.50', dbSystem: 'PostgreSQL');
        ForgeOpsTracker::finishTrace('GET /', microtime(true), 10);

        self::assertSame(3, $result);
        $span = $this->onlyChildSpan($traces);
        self::assertEquals((object) ['db.statement' => 'SELECT * FROM orders WHERE total > ?', 'db.system' => 'postgresql'], $span['data']);
    }

    public function testRecordSpanTakesAStatementAndKeepsItsOwnData(): void
    {
        $traces = [];
        $this->initCapturingTraces($traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::recordSpan('Load orders', 'database', microtime(true), 4.0, ['rows' => 3], "SELECT * FROM orders WHERE status = 'paid'");
        ForgeOpsTracker::finishTrace('GET /', microtime(true), 10);

        self::assertEquals((object) ['rows' => 3, 'db.statement' => 'SELECT * FROM orders WHERE status = ?'], $this->onlyChildSpan($traces)['data']);
    }

    public function testAStatementOnANonDatabaseSpanIsIgnored(): void
    {
        $traces = [];
        $this->initCapturingTraces($traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::span('charge', static fn () => null, 'service', statement: 'SELECT 1');
        ForgeOpsTracker::finishTrace('GET /', microtime(true), 10);

        self::assertEquals((object) [], $this->onlyChildSpan($traces)['data']);
    }
}
