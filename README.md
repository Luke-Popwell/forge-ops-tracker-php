# forge-ops/tracker

PHP error reporting client for a [ForgeOps](../../) instance.
Requires PHP 8.1+. Captures uncaught exceptions automatically through framework integrations for
Laravel and Symfony, or a plain exception-handler wrapper outside a framework, lets you report
caught exceptions explicitly, and scrubs likely personal data before anything leaves the process.

## Installation

Not yet published to Packagist -- install directly from this path (or a local checkout, once split
into its own repo):

```bash
composer config repositories.forge-ops-tracker path path/to/forge_ops/sdks/php
composer require forge-ops/tracker:@dev
```

## Configuration

Set a DSN (from a project's settings page in ForgeOps), either via the `FORGE_OPS_DSN` environment
variable or explicitly:

```php
use ForgeOps\Tracker\ForgeOpsTracker;

ForgeOpsTracker::init(
    dsn: 'https://<api_key>@your-forgeops-host/api/v1/events', // or leave unset to read FORGE_OPS_DSN
    release: '...',
    environment: 'production',
);
```

### Laravel

```php
// config/app.php
'providers' => [
    ...,
    ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerServiceProvider::class,
],
```

Call `ForgeOpsTracker::init(...)` somewhere early -- a service provider's `register()`, or
`bootstrap/app.php`.

### Symfony

Register `ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerExceptionListener` as a service
(autowired/autoconfigured automatically under most skeleton configs, since it implements
`EventSubscriberInterface`). Call `ForgeOpsTracker::init(...)` somewhere early -- e.g. your
`AppKernel`/`Kernel::boot()`, or a compiler pass.

## What gets reported automatically, and what doesn't

**An exception that crashes a request needs no further wiring at all.** Laravel's `reportable()`
hook and Symfony's `kernel.exception` listener both fire for anything that propagates uncaught out
of a controller, then let the framework handle it exactly as if this client weren't installed.

**An exception your own code catches and handles is different -- neither integration ever sees
it**, since it never propagates far enough to reach either hook:

```php
try {
    chargeCard($order);
} catch (CardException $e) {
    $logger->warning("card declined: {$e->getMessage()}");
    // ForgeOps never sees this -- caught locally, never reaches the
    // reportable()/kernel.exception hook at all.
}
```

Neither framework has a global hook for an exception your own code already caught -- report it
explicitly instead, right at the catch site:

```php
catch (CardException $e) {
    ForgeOpsTracker::captureException($e, ['order_id' => $order->id]);
    $logger->warning("card declined: {$e->getMessage()}");
}
```

### Outside a web request (scripts, Artisan/console commands)

`ForgeOpsTracker::init()` also installs a `set_exception_handler()` wrapper by default
(`installExceptionHandler: false` to opt out), which reports anything that crashes the script
outright with no wiring needed -- the same "unhandled needs no wiring" case the Laravel/Symfony
integrations cover for web requests. It still calls whatever handler was already installed
afterward, so it never changes program behavior. This does **not** catch a web request's unhandled
exception under a real app server -- Laravel/Symfony catch that themselves, long before it would
ever reach here.

## Delivery: not a background thread

A typical PHP request (PHP-FPM or similar) is single-threaded and shared-nothing between requests,
so there's no persistent worker process to host a background thread in the first place. Instead,
`DeliveryQueue` defers delivery via `register_shutdown_function()` + `fastcgi_finish_request()`
(when available, i.e. under PHP-FPM specifically): the shutdown callback runs after the script
would otherwise have ended, and `fastcgi_finish_request()` flushes the response to the visiting
user *first* -- so the actual HTTP call(s) to ForgeOps happen after their connection has already
been served, adding no latency they'd notice. This is the standard, idiomatic substitute real PHP
error trackers use for the same problem. Outside FPM (plain CLI, where `fastcgi_finish_request()`
doesn't exist at all), delivery still happens in the shutdown function, just without that
"already sent" guarantee.

`DeliveryQueue::flush()` is public for the same reason: a long-running CLI worker that processes
many units of work in one process can call it explicitly after each one, rather than only ever
getting a real flush at final process exit.

Every failure mode -- network errors, timeouts, a full queue, a malformed DSN -- is caught and
dropped rather than thrown, so a broken or unreachable tracker can never take down the host app.

## `in_app` backtrace frames

PHP runs interpreted directly from real `.php` files on disk, so file-path matching against
`Configuration::$appRoot` is straightforward: each backtrace frame's file path is compared against
the configured app root, and anything under it is marked `in_app`. Defaults to the current working
directory; set it explicitly if that doesn't match your app's actual layout. `vendor/` frames are
never marked `in_app`, regardless of `appRoot`.

Note also that PHP's own backtrace format (`Throwable::getTrace()`) is call-site-shifted: each
frame's file/line is *where that frame's function was called from*, not where the function itself
executes. `EventBuilder` re-pairs this into a consistent file/line/method-per-frame shape (verified
directly against a real nested throw, not assumed) -- see its source comment for the full
explanation. A related quirk: PHP captures an exception's backtrace at *construction* time, not at
`throw` time, so there's no "empty backtrace" case for an exception that's constructed but never
thrown.

## Source context

By default, each in-app backtrace frame (never a `vendor/` dependency) is captured along with the
5 lines of source on either side of the culprit line, read straight off disk at throw-time, so an
issue's detail page can show the actual code that broke, not just a `file:line:method` reference.
This never applies to a frame outside `appRoot`, and it fails silently (no context, not an error)
for any file that can't be read for whatever reason.

This is a real, deliberate exception to "off by default is safer": literal source code is being
transmitted, not just a reference to it, and the real protection here is not this flag. Every
project on ForgeOps has its own setting (on by default, off durably and immediately once an org
owner turns it off, regardless of what any individual app's own `captureSourceContext` is still set
to) that governs whether the server will ever actually store what a client sends, see the in-app
help docs. Use this option if you'd rather this client never even attempt the disk read in the
first place:

```php
ForgeOpsTracker::init(dsn: '...', captureSourceContext: false);
```

## PII scrubbing

The message, backtrace, and any context/tags you attach are scanned for likely personal data --
email addresses, formatted SSNs/credit cards, known API key/token formats, and anything under a
suspiciously-named key (`password`, `api_key`, `ssn`, and similar) -- and redacted before the
payload ever leaves this process. ForgeOps itself scrubs again on arrival regardless, so this is a
second, earlier layer, not the only one.

To disable it:

```php
ForgeOpsTracker::init(dsn: '...', scrubPii: false);
```

## Running the tests

```bash
cd sdks/php
composer install
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
```
