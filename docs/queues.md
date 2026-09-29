# Queues

This guide covers deferring work to the background: publishing a product at a future date, or sending 500 emails without making a visitor wait. The API follows Laravel's queued jobs (`ShouldQueue`, `Queueable`, `Job::dispatch()`), adapted to the framework's snake_case naming and to the fact that WordPress has no `queue:work` daemon. Jobs are stored in a database table, and WP-Cron plus self-chaining background requests run them.

## Table of contents

1. [Quick start](#1-quick-start)
2. [Writing jobs](#2-writing-jobs)
   - [Models on a job](#models-on-a-job)
3. [Dispatching jobs](#3-dispatching-jobs)
4. [How jobs run on WordPress](#4-how-jobs-run-on-wordpress)
5. [Failures and retries](#5-failures-and-retries)
6. [Configuration](#6-configuration)
7. [Loopback blocked or low traffic](#7-loopback-blocked-or-low-traffic)
8. [Events](#8-events)
9. [Testing](#9-testing)
10. [Where this differs from Laravel](#10-where-this-differs-from-laravel)
11. [CLI reference](#11-cli-reference)

---

## 1. Quick start

The queue is **off** until your plugin opts in. A plugin that never queues anything pays nothing for it: no cron event, no AJAX endpoint, no query.

**1. Enable it** by adding the provider to `bootstrap/providers.php`:

```php
return [
    AppServiceProvider::class,
    Framework\Queue\QueueServiceProvider::class,
];
```

**2. Create the tables.** `queue:table` writes two migration classes. It does not run any SQL.

```bash
wp kirki queue:table
```

It prints the two lines to add to `config/migrations.php`. Add them, then run:

```bash
wp kirki migrate
```

**3. Write a job:**

```bash
wp kirki make:job PublishScheduledProduct
```

```php
namespace Acme\Shop\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

class PublishScheduledProduct implements ShouldQueue
{
    use Queueable;

    public $product_id;

    public function __construct(int $product_id)
    {
        $this->product_id = $product_id;
    }

    public function handle()
    {
        wp_update_post(['ID' => $this->product_id, 'post_status' => 'publish']);
    }
}
```

**4. Dispatch it:**

```php
PublishScheduledProduct::dispatch(123)->delay($publish_at);   // a DateTimeInterface
```

---

## 2. Writing jobs

A job is a class that implements `Framework\Contracts\ShouldQueue`, uses the `Framework\Queue\Concerns\Queueable` trait, and has a `handle()` method.

**Job state is frozen at dispatch.** When a job is dispatched, the job object is serialized into the queue table. It is restored when it runs, which may be minutes or days later. Scalars and arrays are fine. Any other object on a property, a `WP_Post` included, is frozen as it was at dispatch time, so store its ID and load it fresh in `handle()`. Framework models are the exception, as long as the job uses `SerializesModels` (see [Models on a job](#models-on-a-job)).

**`handle()` can ask for services.** Class-typed parameters are resolved from the container:

```php
public function handle(Mailer $mailer)
{
    $mailer->send($this->cart_id);
}
```

**Job settings** are optional properties on the class:

| Property | Meaning | Default |
|---|---|---|
| `$tries` | How many times the job may be attempted | `config('queue.tries')`, 1 |
| `$backoff` | Seconds to wait before a retry, or an array of seconds per attempt | `config('queue.backoff')`, 0 |

```php
class SendAbandonedCartEmail implements ShouldQueue
{
    use Queueable;

    protected $tries = 3;
    protected $backoff = [60, 300];   // 1 minute, then 5 minutes (and 5 minutes after that)
}
```

**A job can set its own dispatch defaults** in its constructor, and a dispatch can still override them:

```php
public function __construct(int $cart_id)
{
    $this->cart_id = $cart_id;
    $this->on_queue('emails')->with_priority(5);
}
```

**Inside `handle()`**, a job can inspect and control itself:

| Method | Effect |
|---|---|
| `$this->attempts()` | The attempt this run is, counting from 1 |
| `$this->release($delay)` | Put the job back on the queue after `$delay` seconds. `failed()` is not called. The attempt still counts toward `$tries` |
| `$this->fail($exception)` | Send the job straight to the failed jobs table, regardless of the tries it has left |

```php
public function handle()
{
    if (!$this->api->is_available()) {
        $this->release(120);

        return;
    }

    // ...
}
```

### Models on a job

Add `Framework\Queue\Concerns\SerializesModels` next to `Queueable` and a job can hold models directly. `make:job` includes it by default.

```php
use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use Framework\Queue\Concerns\SerializesModels;

class SendOrderReceipt implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle()
    {
        // $this->order was fetched from the database just now, not at dispatch.
    }
}

SendOrderReceipt::dispatch($order);
```

**What gets stored.** A property holding a saved model is stored as its class, its primary key, and the names of the relations loaded on it. A property holding a model collection (`Framework\Database\Query\Collection`) is stored as the model class, the ordered list of keys, and the collection class. The attributes are not stored, so the payload stays small.

**What gets restored.** When the job runs, each model is fetched again by its key, and the relations that were loaded at dispatch are eager-loaded again, nested ones included (`$order->load('items.product')` comes back with `items.product` loaded). `handle()` therefore sees current data.

**Skipping the relations.** Pass `$order->without_relations()` to store the model without its loaded relations. The original `$order` keeps them.

```php
$this->order = $order->without_relations();
```

**Collections** are fetched in one query and keep their original order. Rows deleted since dispatch are dropped without an error. A collection mixing model classes, for example orders and customers, can't be queued: dispatching it throws a `LogicException`.

**What is left as it is:**

- A model that has never been saved has no row to fetch it back from, so it is stored whole, as it would be without the trait. The same goes for a collection containing one.
- Models nested inside an array or another object are not converted. Only values held directly on a property are.
- Static properties are not stored, and a typed property that was never assigned stays unassigned.

**When a model has been deleted.** If a stored model no longer exists when the job runs, the job can't be built, so it doesn't run:

- By default it moves straight to the failed jobs table with a `ModelNotFoundException`, whatever `$tries` it has left. `JobFailed` fires and the failure is logged. The job's `failed()` method is **not** called, because there is no job instance to call it on.
- If a missing model simply means the work no longer matters, declare `$delete_when_missing_models`. The job is then deleted without a trace: no failed row, no event, no log.

```php
class SendOrderReceipt implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public $delete_when_missing_models = true;
}
```

**Adding or removing the trait.** A job that gains `SerializesModels` still restores payloads queued before the change, with those models frozen as they were. Removing the trait is different. Payloads queued while it was in use hold identifiers rather than models, so drain the queue before deploying a job without it.

**Jobs must be idempotent.** A job can run more than once: after a retry, or if it outlives `retry_after` (see [section 6](#6-configuration)). Running it a second time must be harmless.

---

## 3. Dispatching jobs

```php
SendAbandonedCartEmail::dispatch($cart_id);                              // as soon as possible
SendAbandonedCartEmail::dispatch($cart_id)->delay(3600);                 // in an hour
SendAbandonedCartEmail::dispatch($cart_id)->delay(new DateTime('+1 day'));
SendAbandonedCartEmail::dispatch($cart_id)->delay(new DateInterval('PT30M'));
SendAbandonedCartEmail::dispatch($cart_id)->on_queue('emails')->with_priority(10);

SendAbandonedCartEmail::dispatch_if($cart->is_abandoned(), $cart_id);
SendAbandonedCartEmail::dispatch_unless($cart->is_recovered(), $cart_id);

SendAbandonedCartEmail::dispatch_sync($cart_id);                          // run now, in this request
```

`dispatch()` returns a pending dispatch. The row is written when that object is destroyed, which is what allows the chained modifiers. If the queue is not enabled or its table is missing, `dispatch()` throws a `Framework\Exceptions\QueueException` that says what to do.

**Priority** is a number, and higher runs first. Among equal priorities, the job that became available first runs first, then the one dispatched first.

**Queue names** group jobs. The background worker drains *every* queue in priority order. Names let you filter jobs with `queue:work --queue=` and `queue:clear --queue=`, and you can see them in `queue:failed`.

The `Queue` facade covers job instances you have already built, and inspecting the queue:

```php
use Framework\Supports\Facades\Queue;

Queue::push(new SendAbandonedCartEmail($id));
Queue::later(600, new SendAbandonedCartEmail($id));
Queue::size();            // pending, all queues
Queue::size('emails');
Queue::clear('emails');   // delete pending jobs
```

`dispatch_sync()` runs the job immediately without storing it. If the job throws, its `failed()` method is called and the exception is rethrown. `dispatch_sync()` works without the provider.

---

## 4. How jobs run on WordPress

There is no worker process waiting for jobs. Three things start one:

1. **An every-minute WP-Cron event** checks whether any job is due. If none is, it stops there, so a quiet queue costs one indexed query per cron tick. If a job is due, it sends a **non-blocking loopback request** to `admin-ajax.php` and returns immediately. The visitor whose request triggered WP-Cron does not wait.
2. **Dispatching an undelayed job** registers one `shutdown` callback for that request, which sends the same loopback request. "Dispatch now" does not wait for the next cron tick. This happens at most once per request, however many jobs were dispatched.
3. **A worker that runs out of time with jobs still due** sends the loopback request itself. This is the daisy-chain, and it keeps going without any traffic until the queue is empty.

The worker that receives the request works like this:

- It checks the request's signature. The signature is an HMAC keyed with your site's salts and valid for 60 seconds, so strangers cannot start workers.
- It takes the **chain lock**. Only one worker chain runs at a time. A second spawn finds the lock held and does nothing.
- It **claims** a batch of due jobs with a single atomic `UPDATE`, so no job is ever reserved by two workers at once.
- It runs jobs, and claims more batches, until its **time budget** runs out. Jobs it claimed but did not start are handed back without using up an attempt.
- It releases the lock. If jobs are still due, it spawns its successor.

So 500 abandoned-cart emails are processed in a relay of short requests, each bounded by the time budget, rather than in one request that times out.

---

## 5. Failures and retries

When `handle()` throws:

- If the job has tries left, it goes back on the queue after its backoff delay.
- If not, it is moved to the **failed jobs table**, its `failed()` method is called, `JobFailed` is dispatched, and an error is written to the framework log.

```php
public function failed(Throwable $exception)
{
    // Notify someone, undo partial work, ...
}
```

An exception thrown by `failed()` itself is ignored, and one failing job never stops the others in the batch.

**Crashed workers.** If PHP dies in the middle of a job (a fatal error, or a host that kills the process), the job stays reserved. After `retry_after` seconds the reservation counts as abandoned and the job can be claimed again. Every claim uses up an attempt, so a job that keeps crashing its worker ends up in the failed jobs table instead of looping forever.

Inspect and recover failures with the CLI (see [section 11](#11-cli-reference)):

```bash
wp kirki queue:failed
wp kirki queue:retry 5
wp kirki queue:retry all
```

---

## 6. Configuration

Every key is optional. `config/queue.php`:

```php
return [
    'table' => 'kirki_jobs',               // default: {app prefix}jobs
    'failed_table' => 'kirki_failed_jobs', // default: {app prefix}failed_jobs
    'batch_size' => 10,                    // jobs per claim
    'time_limit' => 20,                    // seconds a web worker spends starting jobs
    'retry_after' => 300,                  // seconds before a reservation counts as abandoned
    'tries' => 1,                          // default $tries
    'backoff' => 0,                        // default $backoff
];
```

Table names are given without the WordPress table prefix. They default to your app prefix, so two plugins built on the framework on the same site never share a queue.

The **time budget** of a web worker is the smaller of `time_limit` and 80% of `max_execution_time`. A job that is already running is never cut short, so keep each job well under the budget.

**`retry_after` must be longer than your slowest job.** If a job takes longer than that, another worker can claim it while it is still running.

---

## 7. Loopback blocked or low traffic

The background worker relies on the site being able to send HTTP requests to itself. This fails on:

- staging sites behind HTTP Basic Auth,
- some firewalls and security plugins,
- hosts that block loopback requests.

When that happens, jobs are stored but never run. WordPress's own Site Health screen reports "loopback request failed" in this situation.

WP-Cron also only runs when someone visits. On a quiet site, a delayed job runs at the first visit after its time, not at its time.

The fix for both is a real cron job that runs the queue from WP-CLI:

```
# wp-config.php
define('DISABLE_WP_CRON', true);

# crontab: every minute
* * * * * cd /path/to/site && wp cron event run --due-now >/dev/null 2>&1
* * * * * cd /path/to/site && wp kirki queue:work >/dev/null 2>&1
```

`queue:work` runs jobs directly in the CLI process. It needs no loopback, has no time budget, and stops when the queue is empty.

---

## 8. Events

Dispatched through the framework event system, and only when something listens for them:

| Event | When | Carries |
|---|---|---|
| `Framework\Queue\Events\JobQueued` | After a job is stored | `$id`, `$job` |
| `Framework\Queue\Events\JobProcessing` | Before `handle()` | `$record`, `$job` |
| `Framework\Queue\Events\JobProcessed` | After a successful `handle()` | `$record`, `$job` |
| `Framework\Queue\Events\JobFailed` | When a job is moved to failed jobs | `$record`, `$job` (null if it could not be restored), `$exception` |

Failures are also logged whether or not anything listens.

---

## 9. Testing

`Queue::fake()` replaces the queue with an in-memory recorder. Nothing is stored and no worker is spawned. It does not need the provider or the tables.

```php
use Framework\Supports\Facades\Queue;

Queue::fake();

$this->checkout->abandon($cart);

Queue::assert_pushed(SendAbandonedCartEmail::class);
Queue::assert_pushed(SendAbandonedCartEmail::class, function ($job) use ($cart) {
    return $job->cart_id === $cart->id;
});
Queue::assert_pushed_times(SendAbandonedCartEmail::class, 1);
Queue::assert_not_pushed(PublishScheduledProduct::class);
Queue::assert_nothing_pushed();
```

A failed assertion throws PHP's `AssertionError`, which PHPUnit reports as a failure. Successful assertions are not counted by PHPUnit, so a test that only makes queue assertions should call `$this->addToAssertionCount()` to avoid being marked risky.

To test a job's own logic, call `handle()` directly or use `dispatch_sync()`.

---

## 10. Where this differs from Laravel

**There is no daemon.** Laravel's `queue:work` is a long-running process. Here, work is driven by WP-Cron and loopback requests, and `queue:work` is a one-shot drain for system cron. Latency depends on traffic or on your cron (see [section 7](#7-loopback-blocked-or-low-traffic)).

**One worker chain at a time.** Laravel scales by running more workers. Here, a single chain keeps shared hosting from being overwhelmed, and throughput is bounded accordingly.

**The database driver only.** There is no Redis, SQS, or `sync` connection. `dispatch_sync()` covers the synchronous case.

**No per-job `$timeout`.** PHP-FPM cannot interrupt a running `handle()` the way Laravel does with `pcntl`. Stale reservations are recovered with the global `retry_after` instead.

**Priority is a number on the job.** Laravel orders work by the list of queue names a worker is given. Here, the background worker drains every queue by numeric priority, and queue names are for grouping and filtering.

**`SerializesModels` has narrower coverage:**

- **No connection is recorded.** A model's connection is set per class here, not per instance, so a model always comes back on its class's connection.
- **No `#[WithoutRelations]` attribute.** PHP 7.4 has no attributes. Call `$model->without_relations()` before assigning the model instead.
- **Unsaved models are kept whole.** Laravel converts them anyway, and the job then fails when it runs.
- **No pivot or morph handling.** Laravel restores pivot models and morph maps. Here only the relations the ORM can eager-load are re-loaded.
- **The property is snake_case:** `$delete_when_missing_models`, not `$deleteWhenMissingModels`.

**Not implemented:** `ShouldBeUnique`, job chains (`Bus::chain`), job batches (`Bus::batch`), job middleware, rate-limited jobs, `dispatch_after_response`, and failed-job pruning (`queue:prune-failed`).

**Failed jobs are identified by numeric ID** in `queue:retry` and `queue:forget`, not by UUID.

---

## 11. CLI reference

| Command | What it does |
|---|---|
| `queue:table` | Generate the `CreateJobsTable` and `CreateFailedJobsTable` migrations. It never overwrites files and runs no SQL. Available without the provider |
| `make:job <Name>` | Generate a job class in `app/Jobs/`. Available without the provider |
| `queue:work [--once] [--max-jobs=<n>] [--queue=<a,b>]` | Process due jobs in the CLI process |
| `queue:failed` | List failed jobs |
| `queue:retry <id\|all>` | Put failed jobs back on the queue with attempts reset |
| `queue:forget <id>` | Delete one failed job |
| `queue:flush` | Delete all failed jobs |
| `queue:clear [--queue=<name>]` | Delete pending jobs |

Every command other than `queue:table` and `make:job` is available only when `QueueServiceProvider` is registered.
