## Why

Plugins built on the framework have no way to defer work. Publishing a product at a future
date, or sending 500 abandoned-cart emails, either happens inline during a visitor's request,
where it is slow and times out, or through hand-rolled `wp_schedule_single_event()` calls. Those
calls give no retries, no record of failures, no batching, and no protection against a single
cron request running past `max_execution_time`.

Laravel solves this with queued jobs. Its execution model assumes a long-running
`queue:work` daemon, which WordPress hosting does not have. The framework needs the same
developer-facing API (`ShouldQueue`, `Queueable`, `Job::dispatch()`), backed by a database
table and driven by what WordPress does have: WP-Cron triggered by traffic, and non-blocking
loopback requests that daisy-chain until the queue is empty.

## What Changes

- Add a `ShouldQueue` contract and a `Queueable` trait. A job is a class with a `handle()`
  method, and its dependencies are resolved from the container.
- Add `Job::dispatch(...)`, returning a pending dispatch that is written to the queue when the
  pending dispatch is destroyed, with `delay()`, `on_queue()` and `with_priority()`. Also add
  `dispatch_if()`, `dispatch_unless()` and `dispatch_sync()`.
- Add runtime job control from inside `handle()`: `attempts()`, `release($delay)` and `fail($e)`.
- Add Laravel-style failure handling: `$tries`, `$backoff` and a `failed(Throwable)` hook.
  Jobs that exhaust their tries move to a `failed_jobs` table.
- Add a database-backed queue in two app-prefixed tables (`{app_prefix}jobs`,
  `{app_prefix}failed_jobs`) with configurable names.
- Add background processing:
  - an every-minute WP-Cron sweeper
  - a spawn on `shutdown` after an undelayed dispatch
  - an HMAC-signed, non-blocking `admin-ajax` loopback worker
  - atomic batch claiming, bounded by batch size and a time budget
  - self-spawning continuation, with one worker chain at a time
- Add stale-reservation recovery through a global `retry_after`.
- Add a `Queue` facade (`push`, `later`, `size`, `clear`) and `Queue::fake()` with
  `assert_pushed`, `assert_not_pushed`, `assert_nothing_pushed` and `assert_pushed_times`.
- Add four events, `JobQueued`, `JobProcessing`, `JobProcessed` and `JobFailed`, and log failures
  through `Log`.
- Add WP-CLI commands: `queue:table` (generates two migration files; it does not run DDL),
  `queue:work`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:flush`, `queue:clear` and
  `make:job`.
- Add an optional `config/queue.php`. The subsystem is **opt-in**: a plugin enables it by listing
  `QueueServiceProvider` in its providers. Without the provider, no cron event, AJAX action or
  query is registered.
- Add `docs/queues.md`.

Not breaking. Every addition is new API, and nothing existing changes behaviour.

Deliberately out of scope: a recurring task scheduler (`$schedule->job()->daily()`), which will
be a follow-up change built on this queue. Also out of scope: `ShouldBeUnique`, job chains, job
batches, failed-job pruning, per-job `$timeout`, `SerializesModels`, and queue drivers other than
the database.

## Capabilities

### New Capabilities

- `queue`: Deferring work to background execution on WordPress without a daemon. Covers
  dispatching jobs with delay, queue name and priority; background processing triggered by
  WP-Cron and loopback requests; batch claiming and continuation; retries, backoff and failure
  recording; stale-reservation recovery; the endpoint's authentication; the testing fake;
  lifecycle events; opt-in enablement; and the CLI surface, including migration generation.

### Modified Capabilities

None. `database-migrations` is used as it is: `queue:table` produces ordinary migration classes
that the plugin lists in `config/migrations.php`.

## Impact

**New code:**
- `src/Queue/`: the manager, database queue, worker, loopback spawner, sweeper, payload codec,
  pending dispatch, fake, events and service provider
- `src/Contracts/ShouldQueue.php`
- the `Queueable` trait
- `src/Exceptions/QueueException.php`
- `src/Supports/Facades/Queue.php`
- eight command classes under `src/Console/Commands/`
- stubs for the two migrations and the job class

**Modified code:**
- `src/Application.php`: the alias, and the command map for `queue:table` and `make:job`. The
  remaining `queue:*` commands are registered by the provider.

**Depends on:**
- the `DB` facade and `Connection` for all SQL
- the `Http` facade for the non-blocking loopback
- `CacheManager::lock()` for the single-chain lock
- `EventManager` for events
- `Log` for failures
- the existing migration stubs pattern

**WordPress surface:**
- one custom cron schedule and one recurring cron event
- one `wp_ajax_nopriv_` / `wp_ajax_` action
- a `shutdown` callback, registered only after an undelayed dispatch in that request
- `wp_salt()` for the HMAC key

These are registered only when the provider is enabled. Every hook, action and table name is
namespaced by the app prefix, so several framework-based plugins on one site stay isolated.

**No dependencies added.** The PHP 7.4 minimum is unchanged. New public API carries
`@since 3.2.0`.
