<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Public entry point:
 *
 *     ForgeOpsTracker::init(dsn: "https://<api_key>@your-forgeops-host/api/v1/events");
 *
 * See the README for Laravel/Symfony integration and what gets captured
 * automatically vs. what needs an explicit captureException() call.
 */
final class ForgeOpsTracker
{
    private static ?Configuration $configuration = null;
    private static ?Reporter $reporter = null;
    private static bool $exceptionHandlerInstalled = false;

    /** @var (callable(Throwable): void)|null */
    private static $previousExceptionHandler = null;

    /** @param string[]|null $enabledEnvironments */
    public static function init(
        ?string $dsn = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $serverName = null,
        ?string $appRoot = null,
        ?array $enabledEnvironments = null,
        ?int $queueSize = null,
        ?float $timeout = null,
        ?bool $scrubPii = null,
        ?bool $captureSourceContext = null,
        ?bool $installExceptionHandler = null,
        mixed $logger = null,
    ): Configuration {
        $configuration = self::configuration();

        if ($dsn !== null) {
            $configuration->dsn = $dsn;
        }
        if ($environment !== null) {
            $configuration->environment = $environment;
        }
        if ($release !== null) {
            $configuration->release = $release;
        }
        if ($serverName !== null) {
            $configuration->serverName = $serverName;
        }
        if ($appRoot !== null) {
            $configuration->appRoot = $appRoot;
        }
        if ($enabledEnvironments !== null) {
            $configuration->enabledEnvironments = $enabledEnvironments;
        }
        if ($queueSize !== null) {
            $configuration->queueSize = $queueSize;
        }
        if ($timeout !== null) {
            $configuration->timeout = $timeout;
        }
        if ($scrubPii !== null) {
            $configuration->scrubPii = $scrubPii;
        }
        if ($captureSourceContext !== null) {
            $configuration->captureSourceContext = $captureSourceContext;
        }
        if ($logger !== null) {
            $configuration->logger = $logger;
        }

        if ($installExceptionHandler ?? true) {
            self::installExceptionHandler();
        }

        return $configuration;
    }

    /** @param array<string, mixed> $context */
    public static function captureException(Throwable $throwable, array $context = []): void
    {
        self::reporter()->report($throwable, $context);
    }

    public static function configuration(): Configuration
    {
        if (self::$configuration === null) {
            self::$configuration = new Configuration();
        }

        return self::$configuration;
    }

    private static function reporter(): Reporter
    {
        if (self::$reporter === null) {
            $configuration = self::configuration();
            $client = new Client($configuration);
            $deliveryQueue = new DeliveryQueue($configuration, $client);
            self::$reporter = new Reporter($configuration, new EventBuilder($configuration), $deliveryQueue);
        }

        return self::$reporter;
    }

    /**
     * Reports anything that would otherwise crash the script outright (a
     * plain CLI script, an Artisan/Symfony console command) with no
     * further wiring -- the same "unhandled needs no wiring" case the
     * Laravel/Symfony integrations cover for web requests. Still calls
     * whatever handler was already installed afterward, so it never
     * changes program behavior. This does *not* catch a web request's
     * unhandled exception under a real app server -- Laravel/Symfony
     * catch that themselves, long before it would ever reach here, which
     * is what those integrations are for.
     */
    private static function installExceptionHandler(): void
    {
        if (self::$exceptionHandlerInstalled) {
            return;
        }
        self::$exceptionHandlerInstalled = true;

        self::$previousExceptionHandler = set_exception_handler(static function (Throwable $e): void {
            self::captureException($e);
            if (self::$previousExceptionHandler !== null) {
                (self::$previousExceptionHandler)($e);
            }
        });
    }

    /** @internal not part of the public API -- resets static state between test cases */
    public static function resetForTesting(): void
    {
        if (self::$previousExceptionHandler !== null) {
            set_exception_handler(self::$previousExceptionHandler);
        } else {
            restore_exception_handler();
        }

        self::$configuration = null;
        self::$reporter = null;
        self::$exceptionHandlerInstalled = false;
        self::$previousExceptionHandler = null;
    }
}
