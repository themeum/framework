## 1. Foundations

- [x] 1.1 Create `src/Contracts/ShouldQueue.php` (a marker interface) and `src/Exceptions/QueueException.php` with named constructors `not_enabled()` (names `QueueServiceProvider`), `table_missing($table)` (says to run `queue:table` + `migrate`), `invalid_payload($reason)` and `max_attempts_exceeded($job)`
- [x] 1.2 Create `src/Queue/Payload.php`: `encode($job)` builds the JSON envelope (uuid, display_name, job, max_tries, backoff, data) from a clone with the runtime record cleared; `decode($json)` checks that the class exists and implements `ShouldQueue` before `unserialize`, and that the result is an instance of it afterwards
- [x] 1.3 Add the clock seam: decide whether to reuse `Cache\Concerns\InteractsWithTime` or add `Queue\Concerns\InteractsWithTime` (the only open question in design.md); either way, provide `current_time()` plus delay coercion for `int | DateTimeInterface | DateInterval`
- [x] 1.4 Create `src/Queue/Concerns/Queueable.php`:
  - static `dispatch`, `dispatch_if`, `dispatch_unless`, `dispatch_sync`, with the enabled and table checks run in `dispatch()` before the `PendingDispatch` exists
  - fluent `delay()`, `on_queue()`, `with_priority()` storing their values on the job
  - runtime `attempts()`, `release($delay)`, `fail($e)` backed by an attached record
- [x] 1.5 Create `src/Queue/PendingDispatch.php`: forwards the fluent setters to the job and pushes it in `__destruct`

## 2. Storage

- [x] 2.1 Create `src/Queue/DatabaseQueue.php`:
  - resolve table names from config, defaulting to `{app_prefix}jobs` / `{app_prefix}failed_jobs`
  - `push()` / `later()` insert through `DB`
  - `table_exists()`, memoized per request
- [x] 2.2 Implement the atomic claim: `claim($limit, $token, ?array $queues)` as a single `UPDATE … ORDER BY priority DESC, available_at, id LIMIT n` that also increments attempts and reclaims stale rows via `retry_after`; `reserved($token)` fetches the claimed rows
- [x] 2.3 Implement settlement: `delete($id)`, `release($id, $delay)`, `release_unstarted($ids)` (clears the reservation and decrements attempts), and `fail($record, $e)` (inserts into failed_jobs with a uuid, then deletes from jobs)
- [x] 2.4 Implement inspection: `has_due()` (`SELECT 1 … LIMIT 1`), `size(?$queue)`, `clear(?$queue)`, and the failed-job operations `failed_all()`, `failed_find($id)`, `retry($id|all)` (attempts reset), `forget($id)`, `flush_failed()`

## 3. Processing

- [x] 3.1 Create `src/Queue/Worker.php`:
  - compute the budget as `min(time_limit, 0.8 * max_execution_time)`
  - loop: claim a batch, then run each job while the budget remains, claiming another batch while `has_due()`
  - on exceeding the budget, release unstarted jobs
  - return whether due work remains
- [x] 3.2 Implement running a single job in `Worker`:
  - decode the payload; a decode failure fails the row and moves on
  - `attempts > max_tries` → fail with `max_attempts_exceeded`
  - attach the record, fire `JobProcessing`, call `handle()` through the container
  - honour `release()` and `fail()` if called from `handle()`
  - otherwise delete and fire `JobProcessed`
- [x] 3.3 Implement failure handling in `Worker`:
  - on a throw below `$tries`, release with backoff (seconds, or an array indexed by attempt with the last value reused)
  - at the limit: `fail()`, call the job's `failed()` guarded by try/catch, fire `JobFailed`, and `Log::error`
- [x] 3.4 Create `src/Queue/Spawner.php`:
  - HMAC sign/verify over `action|ts` with a lazily resolved `wp_salt('auth')`, a ±60s window and `hash_equals`
  - `spawn()` sends a non-blocking `Http::as_form()->timeout(0.01)->with_options(['blocking' => false, 'sslverify' => …])->post(admin_url('admin-ajax.php'))`
  - `spawn_if_idle()` acquires the chain lock and spawns only on success
- [x] 3.5 Implement the AJAX worker handler:
  - verify the signature (reject with no claim on failure), then `ignore_user_abort(true)` and a `set_time_limit` raise where allowed
  - acquire the chain lock (exit if it is held), run the `Worker`, release the lock, then spawn a successor if work remains (design.md Decision 7, revised)
  - `wp_die()`
- [x] 3.6 Create `src/Queue/Sweeper.php`: if the chain lock is free and `has_due()`, call `spawn_if_idle()`; otherwise return

## 4. Manager, facade, fake, events

- [x] 4.1 Create `src/Queue/QueueManager.php`:
  - `push`, `later`, `size`, `clear` delegating to `DatabaseQueue`
  - fire `JobQueued` after insert
  - register the single `shutdown` spawn on the first undelayed push in a request
  - `fake()` swaps in `QueueFake`
- [x] 4.2 Create `src/Queue/QueueFake.php`: records pushed jobs, never persists or spawns; `assert_pushed`, `assert_pushed_times`, `assert_not_pushed` and `assert_nothing_pushed` throw a PHPUnit-compatible assertion failure
- [x] 4.3 Create `src/Queue/Events/JobQueued.php`, `JobProcessing.php`, `JobProcessed.php` and `JobFailed.php`, dispatched only when `EventManager::has_listeners()` is true
- [x] 4.4 Create `src/Supports/Facades/Queue.php` with the accessor `'queue'` and `@method static` lines for `push`, `later`, `size`, `clear`, `fake` and the four assertions

## 5. Wiring

- [x] 5.1 Create `src/Queue/QueueServiceProvider.php`:
  - `register()` binds the `'queue'` singleton `QueueManager`
  - `boot()` adds the `{prefix}queue_every_minute` schedule via `cron_schedules`, schedules the `{prefix}queue_sweep` event if it is missing, and hooks `wp_ajax_nopriv_{prefix}queue_work` + `wp_ajax_{prefix}queue_work`
  - `boot()` also registers the provider-scoped CLI commands when WP-CLI is loaded
- [x] 5.2 Add the `'queue'` alias in `src/Application.php`, and register `queue:table` and `make:job` in `register_base_cli_commands()`
- [x] 5.3 Write `example/config/queue.php` in the commented, all-keys-optional style of `example/config/cache.php`, and confirm every default resolves in code with the file absent

## 6. Command line

- [x] 6.1 Add migration stubs `src/Console/stubs/queue-jobs-table.stub` and `queue-failed-jobs-table.stub` using the schema from design.md Decision 3, including the indexes
- [x] 6.2 Implement `QueueTableCommand` (`queue:table`): resolve the table names, write `CreateJobsTable` / `CreateFailedJobsTable` into the migrations directory, refuse to overwrite, and print the `config/migrations.php` lines to add
- [x] 6.3 Implement `QueueWorkCommand` (`queue:work --once --max-jobs= --queue=`), running the `Worker` in-process with no loopback and no chain lock
- [x] 6.4 Implement `QueueFailedCommand`, `QueueRetryCommand`, `QueueForgetCommand`, `QueueFlushCommand` and `QueueClearCommand`
- [x] 6.5 Add `src/Console/stubs/job.stub` and implement `MakeJobCommand` (`make:job`), writing into `app/Jobs/` with the app namespace and refusing to overwrite

## 7. Tests

- [x] 7.1 Add `tests/Support/Queue/`:
  - `ArrayDatabaseQueue`, an in-memory `DatabaseQueue` subclass with claim, reservation and stale semantics driven by a frozen clock
  - a recording `Spawner`
  - sample jobs (succeeding, throwing, releasing, failing itself, injected dependency, `failed()` that throws)
- [x] 7.2 Add any missing WordPress stubs (`admin_url`, `wp_next_scheduled`, `wp_schedule_event`, `ignore_user_abort` guards) to `StubsWordPressFunctions.php`
- [x] 7.3 Test dispatch:
  - defaults
  - `delay` given as int, `DateTimeInterface` and `DateInterval`
  - `on_queue` and `with_priority`
  - `dispatch_if`, `dispatch_unless`, `dispatch_sync`
  - job-constructor defaults overridden by the pending dispatch
  - `not_enabled` and `table_missing` exceptions
- [x] 7.4 Test the payload:
  - property round trip
  - a nonexistent class and a non-`ShouldQueue` class fail without running
  - runtime record not persisted
- [x] 7.5 Test the worker:
  - priority order
  - batch size per claim, several batches within one budget
  - budget exhaustion releases unstarted jobs without consuming attempts
  - success deletes
  - retry with int and array backoff
  - exhausted tries → failed_jobs + `failed()` + event + log
  - a failing job doesn't stop the batch
  - `release()`, `fail()` and `attempts()` from inside `handle()`
- [x] 7.6 Test stale recovery: a reservation older than `retry_after` is reclaimable, and a crash loop ends in failed_jobs via `max_attempts_exceeded`
- [x] 7.7 Test the spawner, sweeper and handler:
  - a valid signature is accepted; unsigned, tampered and expired ones are rejected with no claim
  - the sweeper does nothing when nothing is due and spawns once when something is due
  - no spawn while the chain lock is held
  - the handler chains when work remains and releases the lock when the queue is empty
  - the shutdown spawn is registered once, and only for undelayed pushes
- [x] 7.8 Test the SQL shape of `DatabaseQueue` against `TestWpdb`: the claim statement, `has_due`, `release_unstarted`, and app-prefixed table names
- [x] 7.9 Test `Queue::fake()`: no persistence or spawn, all four assertions passing and failing correctly, and the callback receiving the job
- [x] 7.10 Test opt-in: without the provider, no hooks are registered and dispatch throws
- [x] 7.11 Test the commands: `queue:table` writes two files and refuses to overwrite, `queue:retry all`, `queue:work --once`, `--queue` filtering, and `make:job` output

## 8. Documentation and verification

- [x] 8.1 Write `docs/queues.md` in the structure of `docs/cache.md`. It must cover:
  - table of contents and quick start (enable the provider → `queue:table` → `migrate` → write a job → dispatch)
  - how processing works on WordPress (WP-Cron plus the loopback chain)
  - configuration, failures and retries, the CLI, and testing with `Queue::fake()`
  - a **"Loopback blocked / low traffic"** section recommending `DISABLE_WP_CRON` plus a system cron running `queue:work`
  - **"Where this differs from Laravel"**: no daemon, no per-job `$timeout`, no `SerializesModels`, a database driver only, priority as a number rather than queue order, no unique/chains/batches/pruning, and a single worker chain
- [x] 8.2 Mark all new public API `@since 3.2.0`, and verify every new `src/` file has the file docblock, `@subpackage` where required, and the `ABSPATH` guard
- [x] 8.3 Run `composer phpcs && composer test:unit` and fix any failures
- [ ] 8.4 Manually verify end to end in the Docker playground:
  - `queue:table` → add to `example/config/migrations.php` → `migrate`
  - dispatch a delayed job and an undelayed batch of 30 jobs
  - watch the chain drain them via `wp cron event run` and `curl`
  - confirm with `queue:failed` after forcing a failure
