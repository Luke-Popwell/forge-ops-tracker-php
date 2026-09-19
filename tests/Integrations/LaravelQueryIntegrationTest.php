<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerPerformanceMiddleware;
use ForgeOps\Tracker\PerformanceFlusher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;

/**
 * The one test in this suite that boots a real (Testbench) Laravel application rather than
 * exercising the middleware against a fake $next closure the way
 * LaravelPerformanceIntegrationTest does: DB::listen() genuinely needs a real 'db' binding to
 * fire against, which that lighter-weight style has none of (confirmed directly: without this,
 * DB::listen() throws "Target class [db] does not exist", the real reason
 * ForgeOpsTrackerPerformanceMiddleware guards it behind app()->bound('db') at all).
 */
final class LaravelQueryIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
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

    public function testTimesARealQueryRunDuringTheRequestAsItsOwnKindQuerySample(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/ok'), function () {
            DB::select('select 1');

            return new Response('ok', 200);
        });

        $kinds = array_column($recorded, 2);
        self::assertContains('query', $kinds);
        self::assertContains('controller', $kinds);

        $querySample = $recorded[array_search('query', $kinds, true)];
        // "select 1" has no FROM clause, so this exercises QueryNaming's own fallback path (just
        // the first keyword) rather than its table-name extraction, which is covered directly
        // against realistic SQL in QueryNamingTest instead.
        self::assertSame('SELECT', $querySample[0]);
    }

    public function testAlsoRecordsAQueryBreadcrumbAlongsideThePerformanceSample(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);
        ForgeOpsTracker::startBreadcrumbTrail();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/ok'), function () {
            DB::select('select 1');

            return new Response('ok', 200);
        });

        $queryBreadcrumbs = array_values(array_filter(
            ForgeOpsTracker::currentBreadcrumbs(),
            static fn (array $entry): bool => $entry['category'] === 'query'
        ));
        self::assertCount(1, $queryBreadcrumbs);
        self::assertSame('SELECT', $queryBreadcrumbs[0]['message']);
        self::assertArrayHasKey('duration_ms', $queryBreadcrumbs[0]['data']);
    }

    public function testDoesNotRecordAQueryBreadcrumbWhenTrackBreadcrumbsIsOff(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded, trackBreadcrumbs: false);
        ForgeOpsTracker::startBreadcrumbTrail();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/ok'), function () {
            DB::select('select 1');

            return new Response('ok', 200);
        });

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testDoesNotThrowWhenNoDatabaseConnectionIsConfigured(): void
    {
        // A pure API gateway with no database at all must never have this new addition break
        // request/controller timing, which never depended on a database existing.
        $this->app['config']->set('database.default', null);
        $this->app->forgetInstance('db');

        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), fn () => new Response('ok', 200));

        self::assertSame('ok', $response->getContent());
    }

    /** @param array<int, array{0: string, 1: float, 2: string}> $recorded */
    private function injectPerformanceFlusher(array &$recorded, ?bool $trackBreadcrumbs = null): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            // enabledEnvironments/environment set explicitly so isEnabled() is true:
            // recordBreadcrumb() checks isEnabled() itself, directly, with no mock standing in
            // for it the way the mocked PerformanceFlusher below bypasses that same check.
            enabledEnvironments: ['production'],
            environment: 'production',
            trackBreadcrumbs: $trackBreadcrumbs,
        );

        $flusher = $this->createMock(PerformanceFlusher::class);
        $flusher->method('record')->willReturnCallback(function (string $transactionName, float $durationMs, string $kind = 'controller') use (&$recorded): void {
            $recorded[] = [$transactionName, $durationMs, $kind];
        });

        $property = new ReflectionProperty(ForgeOpsTracker::class, 'performanceFlusher');
        $property->setValue(null, $flusher);
    }
}
