# Changelog

## 0.5.0 (2026-09-25)

- New `ForgeOpsTracker::recordChange($kind, $title, $details = [], $environment = null, $service = null, $actor = null, $url = null, $id = null, $occurredAt = null)` records something that changed in your system (a feature flag, a config value, a hand-run migration) so ForgeOps can show it next to the errors that followed. `$kind` is one of `feature_flag`, `config`, `migration`, `dependency`, `infrastructure`, or `other`; anything else is sent as `other`. Delivered after the response like error events, never throws, and a no-op when the client isn't enabled. `ForgeOpsTracker::flushChanges()` sends right away.
- Changes between deploys are now detected automatically. After `init()`, a shutdown function sends a snapshot of the PHP version and installed Composer package versions, and ForgeOps records whatever changed since the previous one. A marker file in the system temp directory keeps PHP-FPM from sending it on every request: each host sends it once per change. New `detectChanges` option (default `true`) turns this off.
- New `trackEnvVarNames` option (default `false`) adds environment variable names, never values, to that snapshot. Host-specific names (`HOSTNAME`, `PATH`, `PORT`, `LC_*`, Kubernetes service variables, and others) and the client's own `FORGE_OPS_*` settings are always left out.

## 0.4.0

- Every error reported during a request now says where it happened: `transaction_name` (the same `GET users/{id}` name the request's performance sample and root span use; `GET user_detail` in Symfony), `endpoint` (the HTTP method plus the route pattern, `GET /users/{id}`, never the literal path, so no ids or tokens), and `trace_id` (the request's W3C trace id). Laravel names the request as soon as its router matches the route and Symfony as soon as its router has run, so an error reported from inside the controller already has them. Symfony's endpoint comes from the autowired router, looked up only when an error needs it. Left out entirely outside a request. Structured fields, so they're never PII-scrubbed.
- Distributed tracing across services, using the W3C Trace Context standard. A request that arrives with a valid `traceparent` header continues that trace instead of starting its own, and its root span points at the caller's span. New `ForgeOpsTracker::httpSpan($method, $url, $send, $data = [])` records an outgoing call as an `http` span and hands `$send` the `traceparent` header to add, whose parent id is that span, so the next service can continue the trace. `ForgeOpsTracker::currentTraceId()` returns the current id, and `startTrace()` takes an optional `traceparent` for work that isn't a request. New `propagateTraces` (default true) and `tracePropagationTargets` (default null, meaning every host; or a list of hosts, each matching that host and its subdomains, and/or `/`-delimited regular expressions) control where the header goes. A trace id is created for every request even with `trackTracing` off, since it is also what links an error to errors in other services. Trace and span ids are now always W3C shaped and never all zeros.
- An errored request's trace is always sent, however fast it was: an error captured while the request was running, or an exception that escaped the Laravel middleware. Previously only requests slower than `traceCaptureThreshold` were sent.
- An exception that escapes the Laravel performance middleware keeps its request context, user and breadcrumbs when Laravel reports it only after the middleware has finished: they're copied onto the exception on its way out (it's rethrown unchanged).

## 0.3.0

- Database errors now say where to look. When an error carries the SQL behind a failed database call (Laravel's `QueryException::getSql()`, Doctrine DBAL's `DriverException::getQuery()`, or anything they wrap), the event carries the names of the stored procedure, table and view that SQL touched. On by default (`captureSqlObjects`); names are identifiers, never values. New opt-in `captureSqlStatement` (default false) also sends the statement itself, with every string and number replaced by `?`. Each project has its own server-side setting that can stop the statement being stored regardless of this flag; the names are still kept.

This package has no tagged releases yet (see the README's own "Installation" section: install
`dev-main`), so there is no prior version this first entry follows; `0.1.0` below is simply this
CHANGELOG's own starting point, the same "first tracked version, not a real bump from anything"
situation `sdks/dart`'s own `0.1.0` entry documents for an identical reason.

## 0.2.0

- Performance percentiles: every performance sample now carries a small latency histogram alongside
  its count/sum/max (fixed buckets of 50, 100, 250, 500, 1000, 2500, 5000 and 10000ms, plus an
  overflow bucket), so ForgeOps can show an approximate p50/p95/p99 per transaction instead of
  only an average. No new config; this rides the existing performance tracking flag and flush
  interval.

## 0.1.0

- Custom metrics and infrastructure monitoring: `ForgeOpsTracker::captureMetric($name, $value = 1.0)`
  records a named business event (a signup, a payment) and `captureInfrastructureMetric($name, $value,
  $hostname = null)` a CPU/memory/disk reading from one of your own hosts, buffered and delivered as one
  batch per kind after the response (or when a CLI script ends). `flushMetrics()` sends right away.
- Distributed tracing: the Laravel middleware and Symfony listener start a trace per request and
  send it to the new `/spans` endpoint when it took at least `traceCaptureThreshold` (1s);
  `trackTracing: false` turns it off. Laravel database queries become `db` spans automatically.
  `ForgeOpsTracker::span()`/`recordSpan()` add your own; `startTrace()`/`finishTrace()` trace
  something that isn't a request.
- Breadcrumbs: a trail of database queries and the request/controller lifecycle leading up to an
  error, recorded automatically once `ForgeOpsTrackerBreadcrumbMiddleware` (Laravel) or
  `ForgeOpsTrackerBreadcrumbListener` (Symfony) is registered (on by default once registered, same
  as every other automatic instrumentation this client does; opt out with
  `trackBreadcrumbs: false`, or tune `maxBreadcrumbs`, default 30). Laravel queue jobs
  (`ForgeOpsTrackerQueueListener`) get their own fresh trail per attempt too, with a "started job
  X" breadcrumb recorded automatically. `ForgeOpsTracker::addBreadcrumb($message, category:,
  level:, data:)` adds your own, regardless of whether the automatic sources are on, and works
  outside a request or job entirely too (a plain CLI script, a console command). Unlike the
  affected user, a breadcrumb's message/data is scrubbed for likely PII. Held behind a plain
  static property, the same as `$currentUser`, but with an explicit reset at the start of every
  request/job (not just relying on PHP-FPM's own per-request reset) since this client's Laravel
  Octane and queue-worker support both keep one process alive across many requests/jobs, where an
  unreset trail would otherwise leak from one into the next.
