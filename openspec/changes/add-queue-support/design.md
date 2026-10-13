## Context

See `proposal.md` (Why) for the motivation, and `specs/queue/spec.md` for the behaviour this
design must produce. This section covers only the constraints that shape the approach.

**Framework constraints**
- The PHP floor is 7.4.
- The sniffs forbid `private` and camelCase methods (native interfaces excepted).
- The tree is prefixed with `php-scoper` on release, so class names that end up in stored
  payloads must be the *consumer's* job classes. Those are not prefixed. Framework FQCNs must
  never be stored as strings.
- `EventManager` dispatches against a listener map from config. It has no runtime `listen()`.
- `CacheManager::lock($name, $seconds, $owner)` returns a lock with `acquire()`, `release()` and
  `owner()`. `DatabaseLock` is an atomic `INSERT IGNORE` into options, with compare-and-delete on
  release.
- The `Http` client forwards `with_options()` to `wp_remote_request()`, so the
  `blocking => false` and `timeout` options are available through it.
- `TestWpdb` only records SQL; there is no real database in the test process.
- Generators (`make:migration`, `make:class`) read stub files from `src/Console/stubs/` and resolve
  their namespaces through `app()->get_migrations_namespace()` / `qualify_app_namespace()`.
- Core framework providers are registered automatically in `Application`. The queue provider is
  the first one a consumer opts into; that was a deliberate decision, see Decision 11.

**WordPress constraints**
- WP-Cron fires only on traffic. Its smallest built-in schedule is `hourly`.
- `wp-cron.php` itself runs inside a loopback spawned by `spawn_cron()`, unless the site sets
  `DISABLE_WP_CRON` and uses a system cron.
- `wp_salt()` is pluggable and loads after plugins, so it must be resolved lazily and never in a
  constructor.
- MySQL supports `UPDATE … ORDER BY … LIMIT n`, but does not return the affected rows. The worker
  therefore needs a token to find its own claims.
- PHP-FPM gives no way to interrupt a running `handle()` (there is no `pcntl` in web SAPIs).

## Goals / Non-Goals

**Goals**
- Laravel's job-author API in the framework's snake_case, so knowledge carries over.
- Correct under concurrency: no job ever runs twice at the same moment. Retries are allowed.
- Bounded per-request cost, both for the visitor whose request triggers the sweep and for each
  worker hop.
- Every job-handling code path testable without a database or HTTP, using a frozen clock.

**Non-Goals**
- Guaranteed latency. Background progress depends on traffic or a system cron, and the docs
  must say so.
- Parallel throughput. A single chain is a deliberate choice (Decision 7).
- Detecting that loopback requests are blocked on a host. This is covered in the docs instead;
  `queue:work` from a system cron is the fallback.

## Decisions

### 1. Layout: a `src/Queue/` subsystem

Following the pattern of `src/Cache/`:

| Piece | Location |
|---|---|
| Contract | `src/Contracts/ShouldQueue.php` (marker interface) |
| Trait | `src/Queue/Concerns/Queueable.php`, beside the subsystem's own concerns, as `Cache/Concerns` does |
| Manager / facade target | `QueueManager` |
| Storage | `DatabaseQueue`, the only class that writes SQL |
| Processing | `Worker`, `Spawner` (loopback plus signature), `Sweeper` |
| Serialization | `Payload` (encode/decode) |
| Dispatch | `PendingDispatch` |
| Testing | `QueueFake` |
| Events | `Events/JobQueued`, `JobProcessing`, `JobProcessed`, `JobFailed` |
| Wiring | `QueueServiceProvider` |
| Elsewhere | `QueueException` in `src/Exceptions/`; the `Queue` facade in `src/Supports/Facades/` |

*Alternative:* putting `Queueable` in `src/Concerns/` beside `Dispatchable`. Rejected, because
`Dispatchable` is the *event* dispatch trait and its `dispatch()` is incompatible with the job
one. Keeping them in separate namespaces avoids any temptation to merge them.

### 2. Storage seam: `Worker` depends on `DatabaseQueue`'s public methods, not on SQL

`DatabaseQueue` exposes the operations below. `Worker`, `Sweeper` and the CLI commands call only
these.

| Group | Methods |
|---|---|
| Write | `push`, `later` |
| Claim | `claim($limit, $token, ?array $queues)`, `reserved($token)` |
| Settle | `delete($id)`, `release($id, $delay)`, `release_unstarted($ids)`, `fail($record, $e)` |
| Inspect | `has_due()`, `size($queue)`, `clear($queue)`, `table_exists()` |
| Failed jobs | `failed_*` |

The tests get an in-memory double (`tests/Support/Queue/ArrayDatabaseQueue`) with the same
public surface, driven by a frozen clock. `DatabaseQueue` gets its own SQL-shape tests against
`TestWpdb`.

*Alternative:* a `Queue` contract with multiple drivers. Rejected as speculative, since only the
database driver is in scope. The double subclasses `DatabaseQueue` and overrides its methods,
which needs no interface.

### 3. Schema

Columns of `{app_prefix}jobs`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint unsigned auto-increment PK | |
| `queue` | varchar(191) | default `'default'` |
| `priority` | int | default `0` |
| `payload` | longtext | |
| `attempts` | tinyint unsigned | default `0` |
| `reserved_at` | int unsigned, nullable | |
| `reserved_by` | char(32), nullable | |
| `available_at` | int unsigned | |
| `created_at` | int unsigned | |

Indexes: `(reserved_at, available_at, priority)` for the due check, and `(reserved_by)` for
fetching a worker's claims.

Columns of `{app_prefix}failed_jobs`:

| Column | Type |
|---|---|
| `id` | bigint PK |
| `uuid` | char(36), unique |
| `queue` | varchar(191) |
| `payload` | longtext |
| `exception` | longtext |
| `failed_at` | int unsigned |

Epoch integers, not `DATETIME`, as Laravel's `jobs` table uses. This avoids MySQL timezone
ambiguity against `current_time()` and makes the "now" comparison a bound parameter from the
clock seam, never `NOW()`. That is what makes the frozen-clock tests meaningful.

There is no `status` column, which was a deliberate decision. "Pending" means `reserved_at IS
NULL`. "Processing" means it is set and newer than `retry_after`. "Stale" means it is older.

### 4. Atomic claim with a token

```sql
UPDATE {jobs}
   SET reserved_at = :now, reserved_by = :token, attempts = attempts + 1
 WHERE available_at <= :now
   AND (reserved_at IS NULL OR reserved_at <= :now - :retry_after)
   [AND queue IN (...)]                      -- queue:work --queue only
 ORDER BY priority DESC, available_at ASC, id ASC
 LIMIT :batch;
SELECT * FROM {jobs} WHERE reserved_by = :token ORDER BY priority DESC, available_at, id;
```

The token is `bin2hex(random_bytes(16))`, fresh per claim. The single-statement `UPDATE` is
atomic under InnoDB row locks, so two workers cannot reserve the same row, and stale rows are
reclaimed by the same statement. The attempt is incremented on claim, which is Laravel's
semantics, so a crash loop still consumes tries.

Before running each job, the worker checks `attempts > max_tries`. This catches a stale reclaim
that pushed a job past its limit (crash loop). Such a job is failed with a
`MaxAttemptsExceeded`-style `QueueException` instead of running.

*Alternative:* `SELECT … FOR UPDATE SKIP LOCKED`. Rejected, because it needs MySQL 8 / MariaDB
10.6 and an explicit transaction, and WordPress hosting often runs older versions.

### 5. Payload: a JSON envelope around `serialize()`

```json
{"uuid":"…","display_name":"App\\Jobs\\PublishProduct","job":"App\\Jobs\\PublishProduct",
 "max_tries":3,"backoff":[10,60],"data":"O:24:\"App\\Jobs\\PublishProduct\":…"}
```

`Payload::decode()` checks that `job` exists and implements `ShouldQueue` *before* it calls
`unserialize($data, ['allowed_classes' => true])`, then checks that the result is an instance of
`job`. The runtime state that `Queueable` carries while a job runs is the claimed record, which
backs `attempts()`, `release()` and `fail()`. That state is never persisted: `Payload::encode()`
serializes a clone with the record cleared, and the worker attaches the record only after
decoding.

*Why not `allowed_classes => [$job]`:* a job's properties may legitimately hold value objects,
such as a `Somoy` date or a DTO, and a strict allowlist breaks them silently (they become
`__PHP_Incomplete_Class`). The real trust boundary is that only this plugin's own code writes
the table, and the class check stops a tampered row from choosing an arbitrary entry point. The
docs will recommend scalars and IDs.

### 6. Dispatch: `PendingDispatch` writes on `__destruct`

`Queueable::dispatch(...$args)` returns `new PendingDispatch(new static(...$args))`. The fluent
setters store their values on the job itself (`$job->delay`, `$job->queue`, `$job->priority`).
That means a job can set its own defaults in its constructor, and `QueueFake` can read them in
assertions. `__destruct` calls `app('queue')->push($job)`, and `push()` routes to `later()`
when a delay is set.

`dispatch_sync` resolves the job and calls `handle()` through the container, with the same
`failed()` handling but no persistence. `dispatch_if` and `dispatch_unless` mirror the existing
event trait's `Closure|bool` convention.

*Risk:* an exception thrown from `__destruct` during shutdown is fatal and hard to see. To
mitigate it, the "provider missing" and "table missing" checks run in `dispatch()` itself,
before the `PendingDispatch` exists. Only the insert happens in `__destruct`.

### 7. One chain: each worker holds the lock for its own run

*Revised during implementation.* The original plan handed the lock across hops by passing its
owner token in the signed request, and had the next worker rebuild the lock and refresh it. That
fails on sites without a persistent object cache: `DatabaseLock::release()` compare-and-deletes
on the exact value the *acquiring instance* wrote, so a lock rebuilt from its owner token cannot
release it.

The chain lock is `lock("{prefix}queue_chain", ceil(budget) + 60)`.

**Spawning** (sweeper or shutdown): `acquire()` then `release()` at once, as a test for whether a
chain is running. If the acquire fails, skip. Otherwise send the loopback.

**The worker, on arrival:**
1. `acquire()` the lock, and exit without claiming if it can't.
2. Run within the budget, then `release()` using the same instance.
3. Spawn a successor if due work remains.

In the gap between release and the successor's acquire, a sweeper may spawn a second worker.
Whichever loses the acquire exits, and claims are atomic, so nothing runs twice. If a worker
dies holding the lock, it expires after `ceil(budget) + 60` seconds.

*Alternative:* a separate heartbeat option. Rejected, because the existing lock already
provides expiry and compare-and-delete.

### 8. Loopback and signature

`Spawner::spawn()` sends:

```php
Http::as_form()->timeout(0.01)
    ->with_options(['blocking' => false, 'sslverify' => apply_filters('https_local_ssl_verify', false)])
    ->post(admin_url('admin-ajax.php'), [...])
```

These follow core `spawn_cron()`'s conventions. The body is
`action={prefix}queue_work`, `ts`, and `sig = hash_hmac('sha256', "$action|$ts", wp_salt('auth'))`.

The worker verifies the signature with `hash_equals` and requires `abs(now - ts) <= 60`.
Replaying a valid request within that minute can at most start a worker. That worker must
still win the chain lock (Decision 7) and claims atomically, so a nonce store is not worth its
cost.

The worker hooks both `wp_ajax_nopriv_` and `wp_ajax_`, because which one fires depends on
whether the request arrives carrying auth cookies, and the signature, not the session, is the
credential. It calls `ignore_user_abort(true)` and
raises `set_time_limit()` where it is allowed, then finishes with `wp_die()`.

The sweeper's work is exactly: a lock check, then `has_due()` (`SELECT 1 … LIMIT 1`, cheaper than
`COUNT`), then a spawn.

*Alternative:* the sweeper processes the first batch itself. Declined by decision. The
blocked-loopback risk goes to the docs, with `queue:work` as the remedy.

### 9. Time budget

`budget = min(config('queue.time_limit', 20), floor(0.8 * max_execution_time))`, where
`max_execution_time = 0` means the budget is just `time_limit`.

The worker claims a batch of `batch_size`, works through it, and claims another batch while
budget remains and `has_due()` is true. Before each job it checks the elapsed time. Once the
budget is exceeded, it calls `release_unstarted($ids)` for the current batch. That sets
`reserved_at = NULL, reserved_by = NULL, attempts = attempts - 1` on its own token's unstarted
rows, so they are handed back without consuming a try. The worker then continues the chain.

This means batch size bounds a single claim, and time bounds a hop. Ten fast jobs don't cost a
loopback round-trip each, and ten slow ones can't push a hop past `max_execution_time`.

### 10. Clock seam

`Queue\Concerns\InteractsWithTime` provides `current_time(): int` (UTC epoch) and delay coercion
for `int | DateTimeInterface | DateInterval`. It matches `Cache\Concerns\InteractsWithTime`, and
reuses that trait directly if its signature fits, rather than duplicating it. Tests use the
existing `FreezesTime` pattern.

### 11. Opt-in wiring

`QueueServiceProvider::register()` binds `QueueManager` as the `'queue'` singleton and marks the
queue as enabled. `boot()` registers:
- the `cron_schedules` filter, adding `{prefix}queue_every_minute`
- the sweep event, scheduled if it is missing
- the AJAX actions
- the CLI commands `queue:work|failed|retry|forget|flush|clear`

If the provider is absent, `Queueable::dispatch()` finds no `'queue'` binding and throws
`QueueException::not_enabled()`. `queue:table` and `make:job` are registered in
`Application::register_base_cli_commands()`, so the tables can be generated before the provider
is turned on.

### 12. Configuration (`config/queue.php`, every key optional)

`table`, `failed_table`, `batch_size` (10), `time_limit` (20), `retry_after` (300), `tries` (1),
`backoff` (0).

### 13. Shutdown spawn

On the first undelayed `push()` in a request, `QueueManager` registers a single
`shutdown` callback with `add_action('shutdown', …, PHP_INT_MAX)`. Undelayed means that
`available_at <= now`. The callback runs `Spawner::spawn_if_idle()`.

When `QueueFake` is active, the callback is never registered. `dispatch_sync` never registers it
either.

## Risks / Trade-offs

- **[Loopback blocked]** A host with HTTP Basic Auth, or a firewall that drops loopbacks, never
  processes the queue. → The docs have a prominent section on this, recommending
  `DISABLE_WP_CRON` plus a system cron running `wp <base> queue:work`, with the WP-CLI command
  as the supported fallback.
- **[Low traffic]** A delayed job on a quiet site runs when the next visitor arrives, not at
  `available_at`. → The same docs section covers it. This is inherent to WP-Cron.
- **[Handle outlives `retry_after`]** A job running longer than `retry_after` can be reclaimed
  and run a second time while the first run is still going. → `retry_after` defaults to 300,
  well above the 20s budget. The docs say to raise it for long jobs, and that jobs must be
  idempotent (the same caveat Laravel gives).
- **[`unserialize` of DB content]** → The class is checked before and after `unserialize`. Only
  the plugin writes the table. The docs recommend passing IDs.
- **[Lock handoff race]** At most one extra worker exits early. Claims are atomic, so no job runs
  twice.
- **[Payload after class rename]** A rename between dispatch and processing fails the job with an
  explanatory exception, and `queue:retry` can re-run it after a fix.
- **[First opt-in framework provider]** Consumers may expect auto-registration. → The docs start
  with a one-line enable step. `QueueException::not_enabled()` names the provider class to add.

## Migration Plan

This change is purely additive. Nothing happens until a plugin registers `QueueServiceProvider`.

Rollback: remove the provider from `providers.php`. The sweep event stays scheduled, but with no
callback attached it fires into nothing, which is harmless; `wp cron event delete` removes it.
Tables are removed by the plugin's own `migrate:rollback`.

## Open Questions

None. The clock question was resolved in task 1.3: `Cache\Concerns\InteractsWithTime` is reused
directly. Its "forget" semantics for past TTLs live in the cache `Repository`, not in the trait.
