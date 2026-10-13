## Purpose

Lets a plugin defer work to background execution on WordPress, which has no queue daemon. Jobs
are stored in a database table and processed by traffic-triggered WP-Cron and self-chaining
loopback requests, using Laravel's queued-job API.

## ADDED Requirements

### Requirement: Opt-in enablement
The queue SHALL be inactive unless the consuming plugin registers the queue service provider.
While inactive, the framework MUST NOT register any queue cron schedule, cron event, AJAX action
or shutdown callback, and MUST NOT query the queue tables.

#### Scenario: Provider not registered
- **WHEN** a plugin boots without the queue service provider
- **THEN** no queue cron event is scheduled, no queue AJAX action is registered, and no query touches the queue tables

#### Scenario: Dispatch while disabled
- **WHEN** a job is dispatched and the queue service provider is not registered
- **THEN** a queue exception is thrown whose message says the queue service provider must be registered

#### Scenario: Provider registered but tables missing
- **WHEN** a job is dispatched, the provider is registered, and the jobs table does not exist
- **THEN** a queue exception is thrown whose message names the missing table and says to run `queue:table` and `migrate`

### Requirement: Queueable job classes
A queued job SHALL be a class that implements the `ShouldQueue` contract, uses the `Queueable`
trait, and defines a `handle()` method. When the job runs, `handle()` SHALL be called with its
parameters resolved from the application container.

#### Scenario: Handle with injected dependency
- **WHEN** a job whose `handle()` type-hints a container-bound service is processed
- **THEN** `handle()` receives the resolved service and runs

#### Scenario: Job state survives the round trip
- **WHEN** a job is constructed with an ID, a string and an array, dispatched, and later processed
- **THEN** the processed instance has the same property values it had at dispatch

### Requirement: Dispatching jobs
`Job::dispatch(...$args)` SHALL construct the job with the given arguments and store it as one
pending row. The row MUST be written when the returned pending dispatch is destroyed, so fluent
modifiers chained on it take effect. The pending dispatch SHALL support `delay()`,
`on_queue()` and `with_priority()`. `delay()` SHALL accept a number of seconds or a
`DateTimeInterface`. Defaults: available now, queue `default`, priority `0`.

#### Scenario: Immediate dispatch
- **WHEN** `SendEmail::dispatch(42)` is called
- **THEN** exactly one row is stored with queue `default`, priority `0`, zero attempts and an availability time of now

#### Scenario: Delayed dispatch by seconds
- **WHEN** `PublishProduct::dispatch(123)->delay(3600)` is called
- **THEN** the stored row becomes available 3600 seconds after dispatch

#### Scenario: Delayed dispatch by date
- **WHEN** `PublishProduct::dispatch(123)->delay($future_date)` is called with a `DateTimeInterface`
- **THEN** the stored row becomes available at that instant

#### Scenario: Queue name and priority
- **WHEN** `SendEmail::dispatch(1)->on_queue('emails')->with_priority(10)` is called
- **THEN** the stored row has queue `emails` and priority `10`

#### Scenario: Conditional dispatch
- **WHEN** `dispatch_if(false, ...)` or `dispatch_unless(true, ...)` is called
- **THEN** no row is stored

#### Scenario: Synchronous dispatch
- **WHEN** `dispatch_sync(...)` is called
- **THEN** `handle()` runs immediately in the current request and no row is stored

#### Scenario: Job defaults on the class
- **WHEN** a job sets its queue, delay or priority through the `Queueable` setters inside its constructor
- **THEN** those values are used unless the pending dispatch overrides them

### Requirement: Isolated per-plugin storage
Queue tables SHALL default to `{wp_prefix}{app_prefix}jobs` and `{wp_prefix}{app_prefix}failed_jobs`,
and SHALL be overridable in the queue configuration. Cron hooks, the AJAX action and lock names
MUST also be namespaced by the app prefix. As a result, two framework-based plugins on one site
never read, claim or process each other's jobs.

#### Scenario: Two plugins on one site
- **WHEN** plugin A and plugin B both enable the queue and each dispatch a job
- **THEN** each job is stored in its own plugin's table, and each plugin's worker processes only its own

#### Scenario: Configured table name
- **WHEN** the queue configuration sets a table name
- **THEN** dispatching, processing and `queue:table` all use that name

### Requirement: Payload handling
A stored job SHALL record the job class, a display name, its try and backoff settings, and the
serialized job. When the job is restored, deserialization MUST be restricted, so that a payload
naming a class that is not a `ShouldQueue` job is never instantiated and is failed instead of run.

#### Scenario: Unknown or invalid class in payload
- **WHEN** a stored row's payload names a class that does not exist or does not implement `ShouldQueue`
- **THEN** the job is not run, is moved to the failed jobs table with an explanatory exception, and processing continues with the next job

### Requirement: Background triggering
When the provider is enabled, the queue SHALL register an every-minute WP-Cron schedule and a
recurring sweep event. The sweep SHALL check whether any job is due. If none is, it SHALL do
nothing further. If one is, it SHALL start a worker through a non-blocking loopback request. In
addition, a request that dispatches at least one undelayed job SHALL start a worker once on
`shutdown`.

#### Scenario: Nothing due
- **WHEN** the sweep runs and every stored job has a future availability time
- **THEN** no loopback request is sent

#### Scenario: Job becomes due
- **WHEN** the sweep runs after a delayed job's availability time has passed
- **THEN** one non-blocking loopback request is sent to start a worker, and the sweep returns without waiting for it

#### Scenario: Immediate dispatch spawns on shutdown
- **WHEN** a request dispatches three undelayed jobs
- **THEN** exactly one loopback request is sent, during that request's shutdown

#### Scenario: Only delayed dispatches
- **WHEN** a request dispatches only delayed jobs
- **THEN** no loopback request is sent on shutdown

### Requirement: Authenticated worker endpoint
The worker endpoint SHALL accept requests from logged-out and logged-in callers alike, and MUST
verify an HMAC signature that is keyed with a WordPress salt and covers a timestamp. It MUST reject
missing, invalid or expired signatures before claiming any job. Requests the framework itself
sends SHALL carry a valid signature.

#### Scenario: Unsigned request
- **WHEN** the worker endpoint receives a request with no signature
- **THEN** it responds with a rejection and claims no jobs

#### Scenario: Tampered or expired signature
- **WHEN** the worker endpoint receives a signature that does not verify, or whose timestamp is outside the allowed window
- **THEN** it responds with a rejection and claims no jobs

#### Scenario: Framework-issued request
- **WHEN** the sweep or a shutdown spawn sends a loopback request
- **THEN** the worker endpoint accepts it

### Requirement: Single worker chain
At most one worker chain SHALL process the queue at a time. The chain is held by an expiring
lock. A spawn attempt while a live chain holds the lock SHALL be skipped. If a chain dies without
releasing the lock, the lock SHALL expire, and a later sweep SHALL start a new chain.

#### Scenario: Spawn while chain active
- **WHEN** a sweep or shutdown spawn runs while another worker chain holds the lock
- **THEN** no new worker is started

#### Scenario: Dead chain recovered
- **WHEN** a worker is killed mid-run and its lock later expires
- **THEN** the next sweep that finds due jobs starts a new chain

### Requirement: Atomic batch claiming and ordering
A worker SHALL claim due jobs atomically, so that no job is ever reserved by two workers at once.
Due jobs are those whose availability time has passed and that are unreserved, or whose
reservation is stale. The worker SHALL take them in order of priority descending, then
availability time, then insertion order, up to the configured batch size. Named queues SHALL NOT
affect background ordering. The background chain drains every queue.

#### Scenario: Priority order
- **WHEN** jobs with priorities 0, 10 and 5 are all due
- **THEN** they are processed in the order 10, 5, 0

#### Scenario: Batch size respected
- **WHEN** 25 jobs are due and the batch size is 10
- **THEN** a single claim reserves at most 10 jobs

#### Scenario: Concurrent claim
- **WHEN** two workers claim at the same moment
- **THEN** no job is reserved by both

### Requirement: Worker budget and continuation
A worker SHALL keep claiming and processing batches while its time budget remains and due jobs
exist. The time budget is the smaller of the configured `time_limit` and a safe fraction of
`max_execution_time`. Once the budget is used up, the worker MUST NOT start another job. Claimed
jobs it did not start MUST be released, becoming available at once with no attempt consumed. Before exiting,
the worker SHALL check whether due jobs remain. If they do, it SHALL send a new non-blocking
loopback request and hand on the chain. If none remain, it SHALL release the chain lock and exit.

#### Scenario: Several batches within one budget
- **WHEN** 25 fast jobs are due, the batch size is 10, and the budget is not reached
- **THEN** one worker claims three batches in turn and processes all 25 without spawning a successor

#### Scenario: Daisy-chain until empty
- **WHEN** 500 slow jobs are due and each worker's budget runs out before the queue is empty
- **THEN** successive workers each process what fits in their budget and spawn a successor, until no due job remains and the last worker releases the lock without spawning

#### Scenario: Time budget exhausted
- **WHEN** the time budget runs out after 3 of 10 claimed jobs
- **THEN** the other 7 are released unconsumed and a successor worker is spawned

### Requirement: Successful completion
A job whose `handle()` returns without throwing, and that did not release or fail itself, SHALL
be deleted from the jobs table.

#### Scenario: Job succeeds
- **WHEN** a claimed job's `handle()` returns normally
- **THEN** its row is removed from the jobs table

### Requirement: Retries, backoff and failure
Each claim SHALL increment a job's attempts. When `handle()` throws and attempts are still below
`$tries` (default configured, falling back to 1), the job SHALL be released with the delay given
by `$backoff`. `$backoff` is either seconds, or an array of seconds indexed by attempt, with the
last value reused. When attempts reach `$tries`, the job SHALL be removed from the jobs table and
recorded in the failed jobs table with the exception, and its `failed(Throwable)` method SHALL be
called if defined. An exception thrown by `failed()` MUST NOT stop the worker.

#### Scenario: Retry with backoff
- **WHEN** a job with `$tries = 3` and `$backoff = [10, 60]` throws on its first attempt
- **THEN** it becomes available again 10 seconds later with attempts 1

#### Scenario: Exhausted tries
- **WHEN** a job with `$tries = 3` throws on its third attempt
- **THEN** its row is removed from the jobs table, a failed-jobs row is written with a UUID, queue, payload, exception text and failure time, and `failed()` receives the exception

#### Scenario: One failing job does not stop the batch
- **WHEN** the second of three claimed jobs throws
- **THEN** the first and third still run

### Requirement: Runtime job control
While running, a job SHALL be able to call `attempts()` to read its current attempt number,
`release($delay)` to return itself to the queue after the delay without calling `failed()`, and
`fail($exception)` to go straight to the failed jobs table regardless of remaining tries.

#### Scenario: Release from handle
- **WHEN** `handle()` calls `$this->release(30)` and returns
- **THEN** the job is not deleted and becomes available again 30 seconds later

#### Scenario: Fail from handle
- **WHEN** `handle()` calls `$this->fail($e)` on its first attempt of three
- **THEN** the job is recorded in the failed jobs table and `failed()` is called

#### Scenario: Attempts visible
- **WHEN** a job is processed for the second time
- **THEN** `$this->attempts()` returns 2 inside `handle()`

### Requirement: Stale reservation recovery
A reservation older than the configured `retry_after` (default 300 seconds) SHALL be treated as
abandoned and become claimable again. Reclaiming it SHALL increment attempts, so a job that
repeatedly kills its worker eventually reaches the failed jobs table.

#### Scenario: Worker crashed mid-job
- **WHEN** a job has been reserved for longer than `retry_after` and was never deleted or released
- **THEN** the next claim may reserve it again, with attempts incremented

#### Scenario: Crash loop ends in failure
- **WHEN** a job with `$tries = 2` is reclaimed after its second stale reservation expires
- **THEN** it is moved to the failed jobs table instead of being run a third time

### Requirement: Lifecycle events and logging
The queue SHALL dispatch these events through the framework event system:
- `JobQueued` after a row is stored
- `JobProcessing` before `handle()`
- `JobProcessed` after a successful `handle()`
- `JobFailed` when a job is recorded as failed

Every failure SHALL also be written to the framework log.

#### Scenario: Events for a successful job
- **WHEN** a job is dispatched and processed successfully
- **THEN** `JobQueued`, `JobProcessing` and `JobProcessed` are dispatched, in that order

#### Scenario: Failure is logged
- **WHEN** a job is recorded as failed
- **THEN** `JobFailed` is dispatched and an error naming the job and exception is logged

### Requirement: Queue facade
The `Queue` facade SHALL provide `push($job)`, `later($delay, $job)`, `size(?string $queue)` and
`clear(?string $queue)`. `size` and `clear` SHALL count or delete only unreserved and stale
jobs, optionally filtered to one queue name.

#### Scenario: Size by queue
- **WHEN** two jobs are on `emails` and one on `default`
- **THEN** `Queue::size('emails')` returns 2 and `Queue::size()` returns 3

### Requirement: Testing fake
`Queue::fake()` SHALL replace the queue with an in-memory recorder, so that dispatching stores
nothing and spawns nothing. The recorder SHALL support `assert_pushed($class, $callback = null)`,
`assert_pushed_times($class, $times)`, `assert_not_pushed($class, $callback = null)` and
`assert_nothing_pushed()`. The callback receives the job instance. Failed assertions MUST fail
the calling test.

#### Scenario: Asserting a dispatch
- **WHEN** a test calls `Queue::fake()`, then code under test dispatches `PublishProduct::dispatch(123)`
- **THEN** `Queue::assert_pushed(PublishProduct::class, fn ($job) => $job->product_id === 123)` passes and no row or loopback is produced

### Requirement: Migration generation
`queue:table` SHALL write two migration classes to the plugin's migrations directory, one
creating the jobs table and one creating the failed jobs table, using the resolved table names.
It MUST NOT execute any DDL. It MUST refuse to overwrite existing files, and it SHALL work
whether or not the queue service provider is registered.

#### Scenario: Generate migrations
- **WHEN** `wp <base> queue:table` is run
- **THEN** two migration files exist that, once listed in `config/migrations.php` and run by `migrate`, create both tables, and no table has been created yet

#### Scenario: Files already exist
- **WHEN** `queue:table` is run a second time
- **THEN** it reports an error naming the existing files and leaves them unchanged

### Requirement: Queue CLI commands
When the provider is enabled, the following WP-CLI commands SHALL be available:
- `queue:work [--once] [--max-jobs=<n>] [--queue=<names>]`: process due jobs in the CLI process, without loopback or chain lock handoff, optionally restricted to the named queues
- `queue:failed`: list failed jobs
- `queue:retry <id|all>`: move failed jobs back to the queue with attempts reset
- `queue:forget <id>`: delete one failed job
- `queue:flush`: delete all failed jobs
- `queue:clear [--queue=<name>]`: delete pending jobs

`make:job <Name>` SHALL generate a job class from a stub, and SHALL be available whether or not
the provider is enabled.

#### Scenario: Work once
- **WHEN** `queue:work --once` is run with three jobs due
- **THEN** exactly one job is processed

#### Scenario: Work restricted to queues
- **WHEN** `queue:work --queue=emails` is run
- **THEN** only jobs on the `emails` queue are processed

#### Scenario: Retry all failed
- **WHEN** `queue:retry all` is run with two failed jobs recorded
- **THEN** both are back in the jobs table with attempts reset, and the failed jobs table is empty

#### Scenario: Make job
- **WHEN** `make:job SendAbandonedCartEmail` is run
- **THEN** a class implementing `ShouldQueue`, using `Queueable` and declaring `handle()` is written, and existing files are not overwritten
