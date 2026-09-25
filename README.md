# forge-ops/tracker

PHP error reporting client for [ForgeOps](https://getforgeops.net).
Requires PHP 8.1+. Captures uncaught exceptions automatically through framework integrations for
Laravel and Symfony, or a plain exception-handler wrapper outside a framework, lets you report
caught exceptions explicitly, and scrubs likely personal data before anything leaves the process.

## Installation

Registered on Packagist, but with no tagged release yet: install the `dev-main` branch (it tracks
this package's own public mirror directly, so it's always current, not a stale snapshot):

```bash
composer require forge-ops/tracker:dev-main
```

## Configuration

Set a DSN (from a project's settings page in ForgeOps), either via the `FORGE_OPS_DSN` environment
variable or explicitly:

```php
use ForgeOps\Tracker\ForgeOpsTracker;

ForgeOpsTracker::init(
    dsn: 'https://<api_key>@getforgeops.net/api/v1/events', // or leave unset to read FORGE_OPS_DSN
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

Call `ForgeOpsTracker::init(...)` somewhere early: a service provider's `register()`, or
`bootstrap/app.php`.

Prepend `ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerSessionMiddleware` in
`bootstrap/app.php`'s `withMiddleware()` for [session tracking](#session-tracking-release-health),
`ForgeOpsTrackerPerformanceMiddleware` the same way for
[performance monitoring](#performance-monitoring), `ForgeOpsTrackerUserContextMiddleware` for
[automatic user identification](#identifying-users), and `ForgeOpsTrackerBreadcrumbMiddleware` for
[breadcrumbs](#breadcrumbs):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(ForgeOpsTrackerSessionMiddleware::class);
    $middleware->prepend(ForgeOpsTrackerPerformanceMiddleware::class);
    $middleware->prepend(ForgeOpsTrackerUserContextMiddleware::class);
    $middleware->prepend(ForgeOpsTrackerBreadcrumbMiddleware::class);
})
```

### Symfony

Register `ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerExceptionListener` as a service
(autowired/autoconfigured automatically under most skeleton configs, since it implements
`EventSubscriberInterface`). Call `ForgeOpsTracker::init(...)` somewhere early: e.g. your
`AppKernel`/`Kernel::boot()`, or a compiler pass.

Add `ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerSessionListener` the same way (also
autowired/autoconfigured) for [session tracking](#session-tracking-release-health),
`ForgeOpsTrackerPerformanceListener` the same way again for
[performance monitoring](#performance-monitoring), and `ForgeOpsTrackerBreadcrumbListener` for
[breadcrumbs](#breadcrumbs).

## What gets reported automatically, and what doesn't

**An exception that crashes a request needs no further wiring at all.** Laravel's `reportable()`
hook and Symfony's `kernel.exception` listener both fire for anything that propagates uncaught out
of a controller, then let the framework handle it exactly as if this client weren't installed.

**An exception your own code catches and handles is different: neither integration ever sees
it**, since it never propagates far enough to reach either hook:

```php
try {
    chargeCard($order);
} catch (CardException $e) {
    $logger->warning("card declined: {$e->getMessage()}");
    // ForgeOps never sees this: caught locally, never reaches the
    // reportable()/kernel.exception hook at all.
}
```

Neither framework has a global hook for an exception your own code already caught: report it
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
outright with no wiring needed: the same "unhandled needs no wiring" case the Laravel/Symfony
integrations cover for web requests. It still calls whatever handler was already installed
afterward, so it never changes program behavior. This does **not** catch a web request's unhandled
exception under a real app server: Laravel/Symfony catch that themselves, long before it would
ever reach here.

## Delivery: not a background thread

A typical PHP request (PHP-FPM or similar) is single-threaded and shared-nothing between requests,
so there's no persistent worker process to host a background thread in the first place. Instead,
`DeliveryQueue` defers delivery via `register_shutdown_function()` + `fastcgi_finish_request()`
(when available, i.e. under PHP-FPM specifically): the shutdown callback runs after the script
would otherwise have ended, and `fastcgi_finish_request()` flushes the response to the visiting
user *first*: so the actual HTTP call(s) to ForgeOps happen after their connection has already
been served, adding no latency they'd notice. This is the standard, idiomatic substitute real PHP
error trackers use for the same problem. Outside FPM (plain CLI, where `fastcgi_finish_request()`
doesn't exist at all), delivery still happens in the shutdown function, just without that
"already sent" guarantee.

`DeliveryQueue::flush()` is public for the same reason: a long-running CLI worker that processes
many units of work in one process can call it explicitly after each one, rather than only ever
getting a real flush at final process exit.

Every failure mode (network errors, timeouts, a full queue, a malformed DSN) is caught and
dropped rather than thrown, so a broken or unreachable tracker can never take down the host app.

## Identifying users

```php
ForgeOpsTracker::captureException($e, user: ['id' => $user->id, 'email' => $user->email]);
```

Or `ForgeOpsTracker::setUser(?string $id, ?string $email, ?string $username)` to attach it for
the rest of this request rather than passing it to every `captureException()` call by hand, e.g.
from a Symfony listener (there's no automatic Symfony auth detection yet, so that side is manual;
see below for Laravel, which does have one):

```php
ForgeOpsTracker::setUser(id: (string) $request->user()?->id, email: $request->user()?->email);
```

A plain static property, the same "shared-nothing between requests" reasoning `DeliveryQueue`'s
own comment above already documents for this SDK: PHP-FPM resets every static property at the end
of each request. Laravel Octane (Swoole/RoadRunner) is the real exception to that: it keeps the
whole application, statics included, alive across many requests in one long-running worker
process, so anything that sets this needs its own cleanup rather than relying on the process
itself resetting it, which is exactly why `ForgeOpsTrackerUserContextMiddleware` below clears it
in a `finally`. `id`/`email`/`username` are all independently optional; call `setUser()` with
none of them to clear whatever was set. Shows up on an issue's own detail page, and as its own
affected-users count alongside the regular event count.

**Laravel**: prepend `ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerUserContextMiddleware`
(see the Laravel snippet above) and it detects `$request->user()` for you, whenever your app's
own auth guard resolves one: `getAuthIdentifier()` for `id` (the one thing Laravel's own
`Authenticatable` contract actually guarantees), and `email`/`username` (or `name`, Laravel's own
default scaffolding's field for it) as plain optional attributes, present only if your own `User`
model happens to have them. A no-op for an unauthenticated request, or a route with no auth guard
at all. Composes with the manual API above rather than replacing it: call `setUser()` yourself
afterward (e.g. for a custom auth setup this can't detect, or to override what was auto-detected)
and it wins for the rest of that request.

## Breadcrumbs

A trail of what happened right before an error, on by default, no setup needed once the Laravel
middleware or Symfony listener above is registered: every database query and the request/
controller lifecycle are recorded automatically, and show up alongside the error on an issue's own
detail page. Laravel queue jobs get their own trail too, once
[`ForgeOpsTrackerQueueListener`](#database-queries-and-queue-jobs-laravel) is registered: "started
job X" is recorded automatically at the start of each attempt.

```php
ForgeOpsTracker::init(
    dsn: '...',
    trackBreadcrumbs: false, // opt out of the automatic sources entirely
    maxBreadcrumbs: 30, // oldest entry dropped once this many have accumulated in one trail
);
```

Add your own by hand, regardless of whether the automatic sources are on:

```php
ForgeOpsTracker::addBreadcrumb('charged card', category: 'billing', data: ['order_id' => $order->id]);
```

`category` defaults to `'custom'`, `level` to `'info'` (`'debug'`/`'info'`/`'warning'`/`'error'` are
the four levels the automatic sources themselves use too), and `data` to `[]`. Works outside a
request or job entirely too (a plain CLI script, a console command): the buffer it adds to is
created lazily the first time anything actually calls it, the same "works standalone, no specific
setup required" shape `ForgeOpsTracker::setUser()` already has, rather than silently doing nothing
with no middleware/listener around it.

Each request (or queue job attempt) gets its own fresh, bounded trail (a ring buffer capped at
`maxBreadcrumbs`, oldest entry dropped once full), held behind a plain static property the same way
`$currentUser` already is: see `BreadcrumbBuffer`'s own doc comment for why that's the right call
here even though the Ruby gem needs a thread-local and the Python client needs a `ContextVar`
instead. Laravel Octane (Swoole/RoadRunner) and queue workers both keep one long-running process
alive across many requests/jobs, so `ForgeOpsTrackerBreadcrumbMiddleware`/
`ForgeOpsTrackerBreadcrumbListener`/`ForgeOpsTrackerQueueListener` all explicitly reset this trail
at the start of each one and clear it again at the end, rather than relying on plain PHP-FPM's own
per-request reset, the identical treatment `ForgeOpsTrackerUserContextMiddleware` already gives
`$currentUser` for the same reason.

Unlike the affected user above, a breadcrumb's `message`/`data` **is** scrubbed for likely PII:
query/request trail entries are exactly the kind of free text (a bind parameter showing up in a
message, a URL with a token in it) the scrubber exists to catch, not a deliberately-structured
field the way `user` is.

## `in_app` backtrace frames

PHP runs interpreted directly from real `.php` files on disk, so file-path matching against
`Configuration::$appRoot` is straightforward: each backtrace frame's file path is compared against
the configured app root, and anything under it is marked `in_app`. Defaults to the current working
directory; set it explicitly if that doesn't match your app's actual layout. `vendor/` frames are
never marked `in_app`, regardless of `appRoot`.

Note also that PHP's own backtrace format (`Throwable::getTrace()`) is call-site-shifted: each
frame's file/line is *where that frame's function was called from*, not where the function itself
executes. `EventBuilder` re-pairs this into a consistent file/line/method-per-frame shape (verified
directly against a real nested throw, not assumed): see its source comment for the full
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

The message, backtrace, and any context/tags you attach are scanned for likely personal data
(email addresses, formatted SSNs/credit cards, known API key/token formats, and anything under a
suspiciously-named key like `password`, `api_key`, or `ssn`) and redacted before the
payload ever leaves this process. ForgeOps itself scrubs again on arrival regardless, so this is a
second, earlier layer, not the only one. The user attached via `user:`/`setUser()` above is a
deliberate exception: it's never scrubbed, since redacting it would defeat the whole point of
identifying users in the first place.

To disable it:

```php
ForgeOpsTracker::init(dsn: '...', scrubPii: false);
```

## Session tracking (release health)

By default, once the Laravel middleware or Symfony listener is registered, every request also
counts as a session: crash-free unless an unhandled exception (or a 5xx response) actually affects
it, giving ForgeOps a crash-free rate per release to show alongside the errors themselves, not just
the errors on their own.

```php
ForgeOpsTracker::init(
    dsn: '...',
    trackSessions: false, // opt out entirely
);
```

This is deliberately **not** a port of the other clients' aggregate-flusher design: a typical PHP
request has no persistent process to aggregate counts across many requests in, the same constraint
that already rules out a real background thread for event delivery (see
[Delivery: not a background thread](#delivery-not-a-background-thread) above). Instead, each
request delivers its own checkin, a degenerate one-request "aggregate" (`sessions_count: 1`,
`crashed_sessions_count: 0` or `1`), deferred past the response the same `register_shutdown_function()`
+ `fastcgi_finish_request()` way `DeliveryQueue` already defers event delivery. That's more HTTP
calls and no real batching compared to the other clients, stated plainly rather than hidden behind
the matching class name.

## Performance monitoring

By default, once the Laravel middleware or Symfony listener is registered, every request also
reports its own duration, so a dashboard widget on ForgeOps can show which parts of your app are
actually slow, not just which ones raise. Reported as `"<HTTP method> <route pattern>"` (e.g.
`"GET users/{id}"` for Laravel, `"GET user_detail"` for Symfony's own route name), not the raw
path, so a distinct user id doesn't explode into its own separate transaction.

Each sample also carries a one-bucket latency histogram (which of the fixed latency buckets the
duration fell into: 50, 100, 250, 500, 1000, 2500, 5000 or 10000ms, or an overflow bucket), and
ForgeOps merges these across requests, so it can show an approximate p50/p95/p99 per transaction,
not just an average. Percentiles are accurate to the width of whichever bucket a duration falls into.

```php
ForgeOpsTracker::init(
    dsn: '...',
    trackPerformance: false, // opt out entirely
);
```

Same deliberate one-request-per-report design as session tracking above, for the identical
reason: no persistent process to aggregate across many requests in, so each request delivers its
own sample (`request_count: 1`, `duration_sum_ms`/`max_duration_ms` both the one measured
duration), deferred the same way past the response.

Requires a ForgeOps plan that includes performance monitoring; on a plan that doesn't, the reports
are simply rejected server-side and dropped, exactly like any other delivery failure.

### Database queries and queue jobs (Laravel)

The same middleware also times every database query the request makes, no extra step beyond the
one middleware registration above. Bucketed by `"<VERB> <table>"` (`"SELECT users"`, `"INSERT INTO
orders"`), not the raw SQL text: a low-cardinality name in the same spirit as the request
transaction name above, and never a literal value even where a query's own parameters aren't
already placeholder-bound.

Queue jobs are a separate, opt-in integration, registered once in a service provider's `boot()`:

```php
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerQueueListener;

ForgeOpsTrackerQueueListener::register();
```

Bucketed by the job's own resolved name (`"App\Jobs\SendWelcomeEmail"`), and flushed immediately
after each job rather than waiting for the worker process to eventually exit, since a long-running
worker's own shutdown isn't a meaningful per-job boundary the way one HTTP request's already is.
This is also this SDK's only error-reporting integration for queue jobs: a job that ultimately
fails is reported the same way an unhandled request exception already is, with the job's resolved
name attached as context, no separate wiring needed.

Each shows up as its own `kind` (`"controller"`, `"job"`, `"query"`) on the same `performance`
dashboard dataset, so "slowest queries" and "slowest jobs" are just a filtered version of the same
widget builder "slowest transactions" already uses.

## Distributed tracing

A slow request's own breakdown: which database queries or pieces of your code the time went to,
shown as a span tree on ForgeOps. On by default once the Laravel middleware or Symfony listener is
registered: the request itself becomes the root span (named like its performance transaction),
and it is sent when it took at least `traceCaptureThreshold` seconds (1.0 by default) or when an
error was reported during it, so fast, successful requests cost nothing on the wire.

**Automatic:** the request (Laravel and Symfony) and every database query (Laravel only, the same
`DB::listen` hook performance monitoring already uses; a `database` span named `"SELECT users"`, never
the SQL text). Symfony has no query hook in this integration, and neither framework's outbound HTTP
client is instrumented (there is no stable global hook across supported versions), so add those by
hand (for outbound HTTP, prefer `httpSpan()`, below):

```php
$order = ForgeOpsTracker::span('charge card', fn () => $gateway->charge($id), 'service', ['order' => $id]);
ForgeOpsTracker::span('fetch rates', fn () => $http->get($url), 'http');

// Something you timed yourself (kind is one of controller/service/database/redis/http/job/other):
ForgeOpsTracker::recordSpan('SELECT orders', 'database', $startedAt, $durationMs); // $startedAt is microtime(true)
```

`span()` nests under whichever span is open, records even when the callback throws (rethrowing it
unchanged), and just runs the callback outside a trace. A trace holds at most 500 spans. Each
finished trace is delivered after the response, the same way performance samples are. A queue job
is not traced automatically; wrap one yourself with `ForgeOpsTracker::startTrace()` and
`finishTrace($name, $startedAt, $durationMs)`, then `flushSpans()`. Configure with
`init(trackTracing: false)` and `init(traceCaptureThreshold: 2.5)`.

### Following a request across services

Traces use the [W3C Trace Context](https://www.w3.org/TR/trace-context/) standard (a `traceparent`
header), so an error or a slow call can be followed from one service into the next.

**Incoming**: automatic. A request that arrives with a valid `traceparent` header continues that
trace (same trace id), and its root span records the caller's span as its parent. A missing or
malformed header just starts a new trace.

**Outgoing**: wrap each HTTP call you make during a request in `httpSpan()`. It records the call as
an `http` span named after the method and host (never the path or query) and hands your callback
the headers to add; the header's parent id is that span's own id, so the called service's spans
nest under it. It works with any HTTP client, records the span even when the call throws
(rethrowing it unchanged), and returns whatever your callback returns:

```php
use Illuminate\Support\Facades\Http;

$response = ForgeOpsTracker::httpSpan('POST', $url, fn (array $headers) => Http::withHeaders($headers)->post($url, $order));

// Any other client works the same way: the headers are a plain ['traceparent' => '00-...'] array.
$response = ForgeOpsTracker::httpSpan('GET', $url, fn (array $headers) => $guzzle->get($url, ['headers' => $headers]));
```

Outside a request the callback gets an empty array and nothing is recorded, so the same code works
in a queue job or a script. `ForgeOpsTracker::currentTraceId()` returns the current request's trace
id, for your own logs. To trace work that isn't a request but continues one (a job carrying the
header it was queued with, say), pass that header to `ForgeOpsTracker::startTrace($traceparent)`.

A trace id exists for every request even with `trackTracing: false`, and the header is still sent,
since the trace id is also what links an error here to an error in the service you called (see
[Where an error happened](#where-an-error-happened)); only span reporting stops. The service on the
other end must also report to ForgeOps, and both projects must be linked in ForgeOps to see their
errors and traces connected.

Narrow or turn off where the header goes, for example if a third-party API rejects unknown headers:

```php
ForgeOpsTracker::init(
    dsn: '...',
    propagateTraces: false, // never send traceparent (default true)

    // Default null: every host. A host matches itself and its subdomains on a dot boundary
    // ("internal.example" matches "orders.internal.example", not "notinternal.example"); an entry
    // starting with "/" is a regular expression matched against the host.
    tracePropagationTargets: ['internal.example', '/^10\.0\./'],
);
```

### Where an error happened

An error reported during a request (unhandled, or your own `captureException()` call from inside
the controller) carries three extra fields:

- `transaction_name`: the same name performance monitoring and traces use, `"GET users/{id}"` in
  Laravel or `"GET user_detail"` (the route name) in Symfony.
- `endpoint`: the HTTP method and the route as declared, `"GET /users/{id}"`. Never the literal
  path, so an id or a token in the URL never ends up here. Symfony's comes from the router
  (autowired into `ForgeOpsTrackerPerformanceListener`), looked up only when an error needs it.
- `trace_id`: the request's W3C trace id, which ForgeOps uses to link this error to errors that
  other services reported for the same trace.

They're filled in as soon as the framework has matched the route (Laravel's `RouteMatched` event;
Symfony's router runs before the listener), need the performance middleware or listener to be
registered, and are left out entirely outside a request. They're never PII-scrubbed: they're
structured fields, not free text. An exception that escapes the Laravel middleware keeps them, the
affected user and the breadcrumb trail even if Laravel only reports it after the middleware has
finished: they're copied onto the exception on its way out, and it's rethrown unchanged.

## Custom metrics and infrastructure monitoring

Two explicit calls (nothing is automatic, so there is no `trackMetrics` option): a business event you
name yourself, and a reading from one of your own hosts.

```php
ForgeOpsTracker::captureMetric('signup');          // value defaults to 1.0: a bare counter
ForgeOpsTracker::captureMetric('payment', 49.0);   // a real magnitude; it may be negative (a refund)

ForgeOpsTracker::captureInfrastructureMetric('cpu', 0.42);                 // hostname defaults to serverName
ForgeOpsTracker::captureInfrastructureMetric('disk', 0.81, 'db-1');
ForgeOpsTracker::flushMetrics();                                           // optional: send right now
```

Captures are buffered and delivered as one batch per kind from a shutdown function, after the
response has been sent (`fastcgi_finish_request()` under PHP-FPM), the same way performance samples
are, so a request that captures a metric never waits on the network. A CLI cron script that captures a
few readings and ends needs nothing more: its shutdown function delivers them (an end-to-end test runs
exactly that). Call `flushMetrics()` if it might exit another way. Every entry is stored as it was
captured (a signup is a row, not a running total), so a count or sum you compute later is exact. Both
are a no-op when the client isn't enabled for the environment.

A failed delivery keeps every entry for a later `flushMetrics()` (which matters to a long-running
worker; a normal request's shutdown flush is its only one). The buffer holds at most 1000 entries per
kind and drops further ones once full, since a plan without the feature rejects every flush and a
worker would otherwise grow it without bound. A NaN or infinite value is dropped at capture:
`json_encode` fails on one, which would make the whole batch fail to send. Requires a ForgeOps plan
that includes custom metrics / infrastructure monitoring.

## What changed

ForgeOps can show what changed in your system next to the errors and slowdowns that followed it.
Two ways in:

**Record a change yourself** when something changes that no deploy captures, like a feature flag
flipped, a config value edited, or a migration run by hand:

```php
use ForgeOps\Tracker\ForgeOpsTracker;

ForgeOpsTracker::recordChange(
    'feature_flag', // feature_flag, config, migration, dependency, infrastructure, or other
    'Enabled new_checkout for 10% of users',
    ['flag' => 'new_checkout', 'rollout_percent' => 10],
    actor: 'ops@example.com',
    url: 'https://flags.example.com/new_checkout',
);
```

`$kind` and `$title` are required; `$details`, `$environment` (defaults to the configured one),
`$service`, `$actor`, `$url`, `$id` (an idempotency key, so sending the same change twice records it
once), and `$occurredAt` (a `DateTimeInterface` or ISO 8601 string, defaulting to now) are optional.
An unknown `$kind` is sent as `other`. It's delivered from a shutdown function after the response
has been sent, the same way error events are, never throws, and is a no-op when the client isn't
enabled for the environment. A long-running worker can call `ForgeOpsTracker::flushChanges()` to
send right away.

**Changes between deploys are detected for you.** After `init()`, a shutdown function sends
ForgeOps a snapshot of what the app is running: the PHP version and every installed Composer
package version (from `Composer\InstalledVersions`). ForgeOps compares it with the previous one and
records whatever changed, such as a package upgrade. Since PHP-FPM starts every request fresh, the
client keeps a small marker file in the system temp directory with a hash of the last snapshot it
sent, so each host sends it once per change rather than on every request (and sends nothing if that
file can't be written).

```php
ForgeOpsTracker::init(
    dsn: 'https://<api_key>@getforgeops.net/api/v1/events',
    detectChanges: true,     // default; false sends no snapshot
    trackEnvVarNames: false, // default; true also sends environment variable names
);
```

With `trackEnvVarNames` on, the snapshot lists the names of your environment variables (never their
values), so an added or removed variable shows up as a change. Names that differ from host to host,
like `HOSTNAME`, `PATH`, `PORT`, `LC_*`, and Kubernetes service variables, are left out, as are the
client's own `FORGE_OPS_*` settings. Under PHP-FPM these are the pool's variables, which
`clear_env` empties by default.

Requires a ForgeOps plan that includes change tracking; on a plan that doesn't, both are rejected
server-side and dropped, exactly like any other delivery failure.

## Database errors

When an error comes from a database call, the event includes the names of the stored procedure, table and view its SQL touched, so the issue tells you where to start looking. This is on by default and sends identifiers only, never values. The statement is read from `getSql()` on Laravel's `QueryException`, or from Doctrine DBAL's `DriverException::getQuery()`, on the exception or anything it wraps (`getPrevious()`). A raw `PDOException` carries none.

To also send the SQL statement itself, opt in. Every string and number is replaced by `?` before it
leaves your process (`WHERE email = 'a@b.co' AND id = 42` is sent as `WHERE email = ? AND id = ?`),
and ForgeOps masks it again on arrival:

```php
// Opt in to also sending the masked statement (default false).
ForgeOpsTracker::init(dsn: '...', captureSqlStatement: true);
// captureSqlObjects: false stops even the names (default true)
```

Each ForgeOps project also has its own "Capture the SQL behind database errors" setting. Turn it off
there and the statement is never stored for that project, whatever this flag says; the names are
still kept. A view and a table are written the same way in SQL, so both show as tables/views; the
database's own error message usually settles which it was.

## Running the tests

```bash
cd sdks/php
composer install
vendor/bin/phpunit
vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
```
