# Changelog

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
