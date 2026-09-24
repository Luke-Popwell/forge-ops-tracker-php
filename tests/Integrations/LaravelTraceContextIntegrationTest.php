<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerPerformanceMiddleware;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerServiceProvider;
use ForgeOps\Tracker\Tests\CapturesPayloads;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * Runs real requests through a booted Laravel app (Testbench) with the performance middleware
 * registered globally, the way the README says to, so route matching, the RouteMatched event and
 * Laravel's own exception reporting are the real ones: the order in which those happen relative to
 * this middleware is exactly what the trace context depends on.
 */
final class LaravelTraceContextIntegrationTest extends TestCase
{
    use CapturesPayloads;

    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PARENT_ID = '00f067aa0ba902b7';

    /** @var array<int, array<string, mixed>> */
    private array $events = [];

    /** @var array<int, array<string, mixed>> */
    private array $traces = [];

    protected function getPackageProviders($app): array
    {
        return [ForgeOpsTrackerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(Kernel::class)->prependMiddleware(ForgeOpsTrackerPerformanceMiddleware::class);
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->get('/orders/{id}', static function (string $id): string {
            ForgeOpsTracker::captureException(new RuntimeException('handled ' . $id));

            return 'ok';
        });
        $router->post('/checkout/{cart}', static function (): never {
            throw new RuntimeException('unhandled');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureEventsAndTraces($this->events, $this->traces);
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        parent::tearDown();
    }

    public function testAHandledErrorMidRequestCarriesTheContinuedTraceAndTheRouteTemplate(): void
    {
        $this->get('/orders/42', ['traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01'])->assertOk();
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $this->events);
        self::assertSame(self::TRACE_ID, $this->events[0]['trace_id']);
        self::assertSame('GET orders/{id}', $this->events[0]['transaction_name']);
        self::assertSame('GET /orders/{id}', $this->events[0]['endpoint']);
        // A fast request, but it reported an error, so its trace went out, under the caller's span.
        self::assertCount(1, $this->traces);
        self::assertSame(self::TRACE_ID, $this->traces[0]['trace_id']);
        self::assertSame(self::PARENT_ID, $this->traces[0]['spans'][0]['parent_span_id']);
    }

    public function testAnUnhandledErrorReportedByLaravelCarriesTheRequestContext(): void
    {
        $this->post('/checkout/9')->assertStatus(500);
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $this->events);
        self::assertSame('unhandled', $this->events[0]['message']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $this->events[0]['trace_id']);
        self::assertSame('POST checkout/{cart}', $this->events[0]['transaction_name']);
        self::assertSame('POST /checkout/{cart}', $this->events[0]['endpoint']);
        self::assertSame($this->events[0]['trace_id'], $this->traces[0]['trace_id']);
    }

    public function testAMalformedTraceparentStartsAFreshTrace(): void
    {
        $this->get('/orders/1', ['traceparent' => '00-' . strtoupper(self::TRACE_ID) . '-' . self::PARENT_ID . '-01'])->assertOk();
        ForgeOpsTracker::flushSpans();

        self::assertNotSame(self::TRACE_ID, $this->events[0]['trace_id']);
        self::assertNull($this->traces[0]['spans'][0]['parent_span_id']);
    }

    public function testNothingLeaksIntoWorkAfterTheRequest(): void
    {
        $this->get('/orders/1')->assertOk();
        ForgeOpsTracker::captureException(new RuntimeException('later'));

        self::assertNull(ForgeOpsTracker::currentTraceId());
        self::assertArrayNotHasKey('trace_id', $this->events[1]);
    }

    public function testAnExceptionEscapingTheMiddlewareKeepsItsContextWhenReportedAfterwards(): void
    {
        $failure = new RuntimeException('escaped');
        $request = Request::create('/standalone');
        $request->headers->set('traceparent', '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01');

        try {
            (new ForgeOpsTrackerPerformanceMiddleware())->handle($request, static function () use ($failure): never {
                ForgeOpsTracker::setUser(id: '5');
                throw $failure;
            });
            self::fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        ForgeOpsTracker::setUser();

        // What Laravel's kernel does with an exception that escaped the whole pipeline: report it,
        // after every middleware's finally has already run.
        ForgeOpsTracker::captureException($failure);
        ForgeOpsTracker::flushSpans();

        self::assertSame(self::TRACE_ID, $this->events[0]['trace_id']);
        // Never routed, so never named: a raw path is no transaction name.
        self::assertArrayNotHasKey('transaction_name', $this->events[0]);
        self::assertSame(['id' => '5'], $this->events[0]['user']);
        self::assertCount(1, $this->traces);
    }

    public function testNamesTheRequestUpFrontWhenItIsAlreadyRouted(): void
    {
        $request = Request::create('/users/42');
        $request->setRouteResolver(fn () => new class () {
            public function uri(): string
            {
                return 'users/{id}';
            }
        });

        (new ForgeOpsTrackerPerformanceMiddleware())->handle($request, static function (): Response {
            ForgeOpsTracker::captureException(new RuntimeException('boom'));

            return new Response('ok');
        });

        self::assertSame('GET users/{id}', $this->events[0]['transaction_name']);
        self::assertSame('GET /users/{id}', $this->events[0]['endpoint']);
    }
}
