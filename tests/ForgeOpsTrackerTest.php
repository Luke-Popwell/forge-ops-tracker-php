<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\ForgeOpsTracker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class ForgeOpsTrackerTest extends TestCase
{
    private const PORT = 8099;

    private static $serverProcess = null;
    private static string $requestFile;

    public static function setUpBeforeClass(): void
    {
        self::$requestFile = sys_get_temp_dir() . '/forge_ops_tracker_facade_test_request.json';
        @unlink(self::$requestFile);

        $router = __DIR__ . '/fixtures/facade_echo_server.php';
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

    /** @var (callable(Throwable): void)|null */
    private $exceptionHandlerBeforeThisTest;

    protected function setUp(): void
    {
        ForgeOpsTracker::resetForTesting();
        @unlink(self::$requestFile);

        // Captured so tearDown can restore it exactly: confirmed
        // directly (not assumed) that ForgeOpsTracker::resetForTesting()
        // alone isn't sufficient here: it only knows about the one
        // handler *it* installs via init(), not any exception handler a
        // test sets directly (e.g. testInstallExceptionHandlerFalse...,
        // which sets one before calling init() at all). Peek-then-restore
        // like this is net-neutral by itself (pops one, pushes the same
        // one right back), so it doesn't disturb tests that never touch
        // the handler themselves.
        $this->exceptionHandlerBeforeThisTest = set_exception_handler(null);
        if ($this->exceptionHandlerBeforeThisTest !== null) {
            set_exception_handler($this->exceptionHandlerBeforeThisTest);
        }
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();

        if ($this->exceptionHandlerBeforeThisTest !== null) {
            set_exception_handler($this->exceptionHandlerBeforeThisTest);
        } else {
            restore_exception_handler();
        }
    }

    public function testInitConfiguresAndReturnsTheConfiguration(): void
    {
        $config = ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            release: 'abc123',
        );

        self::assertSame('https://key@tracker.example.com/api/v1/events', $config->dsn);
        self::assertSame('abc123', $config->release);
    }

    public function testCaptureExceptionDeliversThroughTheFullStack(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        // flush() is normally deferred to the shutdown function: called
        // directly here since we want to assert on it within this test,
        // not wait for the real PHPUnit process to actually exit.
        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        self::assertSame('Bearer key', $received['headers']['Authorization']);
    }

    public function testCaptureExceptionPassesAnExplicitUserThrough(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error, user: ['id' => '42', 'email' => 'alice@example.com']);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertSame(['id' => '42', 'email' => 'alice@example.com'], $body['user']);
    }

    public function testSetUserAttachesTheUserToALaterCaptureExceptionCall(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        ForgeOpsTracker::setUser(id: '42', email: 'alice@example.com');
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertSame(['id' => '42', 'email' => 'alice@example.com'], $body['user']);
    }

    public function testAnExplicitUserArgumentOverridesWhateverSetUserLastSet(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        ForgeOpsTracker::setUser(id: '42');
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error, user: ['id' => '99']);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertSame(['id' => '99'], $body['user']);
    }

    public function testSetUserWithNoArgumentsClearsWhateverWasSet(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        ForgeOpsTracker::setUser(id: '42');
        ForgeOpsTracker::setUser();
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertArrayNotHasKey('user', $body);
    }

    public function testAddBreadcrumbAttachesTheTrailToALaterCaptureExceptionCall(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        ForgeOpsTracker::addBreadcrumb('opened checkout', category: 'custom');
        ForgeOpsTracker::addBreadcrumb('charged card', category: 'billing', level: 'warning', data: ['order_id' => 42]);
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertCount(2, $body['breadcrumbs']);
        self::assertSame('opened checkout', $body['breadcrumbs'][0]['message']);
        self::assertSame('custom', $body['breadcrumbs'][0]['category']);
        self::assertSame('charged card', $body['breadcrumbs'][1]['message']);
        self::assertSame('billing', $body['breadcrumbs'][1]['category']);
        self::assertSame('warning', $body['breadcrumbs'][1]['level']);
        self::assertSame(['order_id' => 42], $body['breadcrumbs'][1]['data']);
    }

    public function testAddBreadcrumbDefaultsCategoryToCustomAndLevelToInfo(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        ForgeOpsTracker::addBreadcrumb('checkpoint');
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertSame('custom', $body['breadcrumbs'][0]['category']);
        self::assertSame('info', $body['breadcrumbs'][0]['level']);
        self::assertSame([], $body['breadcrumbs'][0]['data']);
    }

    public function testOmitsTheBreadcrumbsKeyWhenNoneWereEverAdded(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $error) {
            ForgeOpsTracker::captureException($error);
        }

        $this->flushPendingDeliveries();

        $received = json_decode((string) file_get_contents(self::$requestFile), true);
        $body = json_decode($received['body'], true);
        self::assertArrayNotHasKey('breadcrumbs', $body);
    }

    public function testAddBreadcrumbWorksStandaloneWithNoRequestMiddlewareOrHttpFrameworkInvolvedAtAll(): void
    {
        // The whole point of a lazily-created buffer (see
        // ForgeOpsTracker::addBreadcrumb()'s own doc comment): a plain CLI script, or a queued
        // job with no listener registered at all, still gets a working trail with zero setup.
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());

        ForgeOpsTracker::addBreadcrumb('ran the script');

        self::assertCount(1, ForgeOpsTracker::currentBreadcrumbs());
        self::assertSame('ran the script', ForgeOpsTracker::currentBreadcrumbs()[0]['message']);
    }

    public function testAddBreadcrumbWorksRegardlessOfWhetherTrackBreadcrumbsIsOn(): void
    {
        // trackBreadcrumbs only gates recordBreadcrumb() (the automatic sources); the manual API
        // is never gated by it, the same contract gems/forge_ops_tracker's own
        // ForgeOpsTracker.add_breadcrumb documents.
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
            trackBreadcrumbs: false,
        );

        ForgeOpsTracker::addBreadcrumb('added by hand anyway');

        self::assertSame('added by hand anyway', ForgeOpsTracker::currentBreadcrumbs()[0]['message']);
    }

    public function testRecordBreadcrumbIsANoOpWhenTrackBreadcrumbsIsOff(): void
    {
        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            enabledEnvironments: ['production'],
            environment: 'production',
            trackBreadcrumbs: false,
        );

        ForgeOpsTracker::recordBreadcrumb('query', 'SELECT users');

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testRecordBreadcrumbIsANoOpWhenTheClientIsNotEnabled(): void
    {
        // Not initialized with a DSN at all: isEnabled() is false.
        ForgeOpsTracker::recordBreadcrumb('query', 'SELECT users');

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testStartBreadcrumbTrailReplacesWhateverTrailWasThereBefore(): void
    {
        ForgeOpsTracker::init(dsn: 'http://key@127.0.0.1:' . self::PORT . '/');

        ForgeOpsTracker::addBreadcrumb('from an earlier, unrelated unit of work');
        ForgeOpsTracker::startBreadcrumbTrail();

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testEndBreadcrumbTrailClearsItBackToEmptyRatherThanLeavingAStaleBuffer(): void
    {
        ForgeOpsTracker::init(dsn: 'http://key@127.0.0.1:' . self::PORT . '/');

        ForgeOpsTracker::addBreadcrumb('one');
        ForgeOpsTracker::endBreadcrumbTrail();

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());

        // A later addBreadcrumb() call still works (lazily creates a fresh buffer), it just
        // doesn't inherit anything from before endBreadcrumbTrail() was called.
        ForgeOpsTracker::addBreadcrumb('two');
        self::assertSame(['two'], array_column(ForgeOpsTracker::currentBreadcrumbs(), 'message'));
    }

    public function testResetForTestingClearsTheBreadcrumbTrailToo(): void
    {
        ForgeOpsTracker::init(dsn: 'http://key@127.0.0.1:' . self::PORT . '/');
        ForgeOpsTracker::addBreadcrumb('leftover from this test');

        ForgeOpsTracker::resetForTesting();

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testExceptionHandlerReportsThenStillCallsThePreviousOne(): void
    {
        // set_exception_handler() always *pushes* onto PHP's internal
        // handler stack (it never replaces it) so restoring by
        // remembering and re-setting a prior value just pushes yet
        // another frame rather than actually popping back down.
        // restore_exception_handler() is the real inverse, popping
        // exactly one frame per call; this test calls
        // set_exception_handler() exactly 3 times below (verified by
        // counting, not assumed), so the finally block calls
        // restore_exception_handler() exactly 3 times to fully unwind
        // back to this test's pre-existing baseline, regardless of what
        // that baseline actually was.
        try {
            $previousHandlerCalls = [];
            set_exception_handler(static function (Throwable $e) use (&$previousHandlerCalls): void {
                $previousHandlerCalls[] = $e;
            }); // push 1/3

            ForgeOpsTracker::init(
                dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
                enabledEnvironments: ['production'],
                environment: 'production',
            ); // push 2/3, internally

            // set_exception_handler()'s return value is whatever handler
            // was previously installed: retrieves ForgeOpsTracker's own
            // wrapper this way, since there's no other way to obtain a
            // reference to it, then invokes it directly rather than
            // actually crashing this test process to observe it fire
            // naturally.
            $installedHandler = set_exception_handler(static function (): void {
            }); // push 3/3
            $error = new RuntimeException('uncaught');
            $installedHandler($error);

            $this->flushPendingDeliveries();

            self::assertCount(1, $previousHandlerCalls);
            self::assertSame($error, $previousHandlerCalls[0]);
            $received = json_decode((string) file_get_contents(self::$requestFile), true);
            self::assertSame('Bearer key', $received['headers']['Authorization']);
        } finally {
            restore_exception_handler();
            restore_exception_handler();
            restore_exception_handler();
        }
    }

    public function testInstallExceptionHandlerFalseLeavesTheExceptionHandlerUntouched(): void
    {
        $original = static function (Throwable $e): void {
        };
        set_exception_handler($original);

        ForgeOpsTracker::init(
            dsn: 'http://key@127.0.0.1:' . self::PORT . '/',
            installExceptionHandler: false,
        );

        $current = set_exception_handler(null);
        self::assertSame($original, $current);
    }

    /**
     * DeliveryQueue::flush() is private to ForgeOpsTracker's internals:
     * reached via Reflection here purely for test purposes, since the
     * facade itself has no public "flush now" API (nothing outside a
     * test needs one; real usage relies on the shutdown function).
     */
    private function flushPendingDeliveries(): void
    {
        // No setAccessible(true): deprecated as of PHP 8.5 (verified
        // directly, not assumed): it's had no effect since PHP 8.1, when
        // all reflection properties became accessible by default.
        $reporterProperty = new \ReflectionProperty(ForgeOpsTracker::class, 'reporter');
        $reporter = $reporterProperty->getValue();

        $deliveryQueueProperty = new \ReflectionProperty($reporter, 'deliveryQueue');
        $deliveryQueueProperty->getValue($reporter)->flush();
    }
}
