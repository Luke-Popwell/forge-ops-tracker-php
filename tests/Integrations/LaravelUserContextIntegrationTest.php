<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerUserContextMiddleware;
use ForgeOps\Tracker\Reporter;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Exercises the middleware class directly against a fake $next closure and a fake Request::
 * setUserResolver(), the same technique LaravelSessionIntegrationTest/LaravelPerformanceIntegrationTest
 * already use: wiring is what's specific to this integration, not real HTTP delivery or a real
 * auth guard, which ForgeOpsTrackerTest/ReporterTest already cover.
 */
final class LaravelUserContextIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testAttachesTheAuthenticatedUserToAReportedException(): void
    {
        $reportedUser = null;
        $this->injectReporterCapturingUser($reportedUser);

        $request = Request::create('/throw');
        $request->setUserResolver(fn () => new FakeAuthenticatable('42', 'ada@example.com', null));

        $middleware = new ForgeOpsTrackerUserContextMiddleware();
        $middleware->handle($request, function () {
            ForgeOpsTracker::captureException(new RuntimeException('boom'));
            return new Response('ok', 200);
        });

        self::assertSame(['id' => '42', 'email' => 'ada@example.com'], $reportedUser);
    }

    public function testDoesNotAttachAUserWhenTheRequestHasNoAuthenticatedUser(): void
    {
        $reportedUser = 'not set at all';
        $this->injectReporterCapturingUser($reportedUser);

        $request = Request::create('/throw');
        // No setUserResolver() at all: the default resolver returns null, the same as an app with
        // no auth guard configured for this route.

        $middleware = new ForgeOpsTrackerUserContextMiddleware();
        $middleware->handle($request, function () {
            ForgeOpsTracker::captureException(new RuntimeException('boom'));
            return new Response('ok', 200);
        });

        self::assertNull($reportedUser);
    }

    public function testClearsTheUserAfterTheRequestEvenWhenItThrows(): void
    {
        $reportedUser = 'not set at all';
        $this->injectReporterCapturingUser($reportedUser);

        $request = Request::create('/throw');
        $request->setUserResolver(fn () => new FakeAuthenticatable('42', null, null));

        $middleware = new ForgeOpsTrackerUserContextMiddleware();
        try {
            $middleware->handle($request, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected: the middleware only observes, see its own doc comment
        }

        // A later report with no request in flight at all (a queued job, a console command) must
        // never see the previous request's user leak into it.
        ForgeOpsTracker::captureException(new RuntimeException('later, unrelated'));
        self::assertNull($reportedUser);
    }

    /** @param mixed $reportedUser */
    private function injectReporterCapturingUser(&$reportedUser): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');

        $reporter = $this->createMock(Reporter::class);
        $reporter->method('report')->willReturnCallback(
            function ($throwable, $context, $user) use (&$reportedUser): void {
                $reportedUser = $user;
            }
        );

        // No setAccessible(true): deprecated as of PHP 8.5, no effect since PHP 8.1 (verified
        // directly, not assumed).
        $property = new ReflectionProperty(ForgeOpsTracker::class, 'reporter');
        $property->setValue(null, $reporter);
    }
}

final class FakeAuthenticatable implements Authenticatable
{
    public function __construct(
        private readonly string $id,
        public readonly ?string $email,
        public readonly ?string $username,
    ) {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getRememberToken()
    {
        return '';
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return 'remember_token';
    }
}
