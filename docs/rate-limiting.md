# Rate limiting

Counts attempts against a key over a window and rejects the ones that exceed it. Routes opt in
through the `throttle` middleware; the limiter underneath is also usable on its own for anything
that needs a ceiling, such as an outbound API call or a password reset email.

Counters live in the [cache](cache.md), so this reads best after that document.

## Table of contents

1. [Quick start](#1-quick-start)
2. [Inline limits](#2-inline-limits)
3. [Named limiters](#3-named-limiters)
4. [Several limits at once](#4-several-limits-at-once)
5. [Segmenting a limit](#5-segmenting-a-limit)
6. [Response headers](#6-response-headers)
7. [Custom rejection responses](#7-custom-rejection-responses)
8. [Trusted proxies](#8-trusted-proxies)
9. [Using the limiter directly](#9-using-the-limiter-directly)
10. [Configuration](#10-configuration)
11. [Concurrency and locks](#11-concurrency-and-locks)
12. [Where this differs from Laravel](#12-where-this-differs-from-laravel)

---

## 1. Quick start

```php
use Framework\Route;

Route::get('/search', [SearchController::class, 'index'])->throttle(60, 1);
```

Sixty requests a minute per caller. The sixty-first is answered `429 Too Many Requests` with a
`Retry-After` header, and the controller never runs.

The same limit written the way Laravel writes it:

```php
Route::get('/search', [SearchController::class, 'index'])->middleware('throttle:60,1');
```

Both forms work on site routes too:

```php
Route::site(function () {
    Route::post('/cart/add', [CartController::class, 'add'])->throttle(10, 1);
});
```

---

## 2. Inline limits

`throttle:<attempts>,<minutes>` — the second argument is a number of **minutes**, matching
Laravel. `throttle:60,1` is sixty per minute; `throttle:60,5` is sixty per five minutes.

The second argument is optional and defaults to one minute:

```php
Route::get('/feed', $handler)->middleware('throttle:30');
```

Applied to a group, the limit covers every route inside it:

```php
Route::group(['prefix' => 'admin', 'middleware' => 'throttle:120,1'], function () {
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/logs', [LogsController::class, 'index']);
});
```

**Each route counts separately.** The key folds in the request method and path, so two routes
sharing a limit declaration do not share an allowance.

---

## 3. Named limiters

Register a limiter once and name it from as many routes as you like. Registration belongs in a
service provider's `boot()`:

```php
use Framework\Http\Request;
use Framework\RateLimiting\Limit;
use Framework\Supports\Facades\RateLimiter;

public function boot()
{
    RateLimiter::for('uploads', function (Request $request) {
        return Limit::per_minute(100);
    });
}
```

```php
Route::post('/audio', $handler)->middleware('throttle:uploads');
Route::post('/video', $handler)->middleware('throttle:uploads');
```

A name is distinguished from an inline limit by not being numeric, so `throttle:60,1` and
`throttle:uploads` need no other marker.

**The callback receives the request**, so a limit can depend on who is asking:

```php
RateLimiter::for('uploads', function (Request $request) {
    return $request->user_id()
        ? Limit::per_minute(100)->by('user:' . $request->user_id())
        : Limit::per_minute(10)->by('ip:' . $request->ip());
});
```

Return `Limit::none()` for callers who should never be limited:

```php
RateLimiter::for('uploads', function (Request $request) {
    return current_user_can('manage_options')
        ? Limit::none()
        : Limit::per_hour(10);
});
```

An unlimited limit writes no counter state at all, so it costs nothing.

The builders: `Limit::per_second()`, `per_minute()`, `per_minutes($minutes, $attempts)`,
`per_hour()`, `per_day()`, and `none()`.

---

## 4. Several limits at once

A limiter may return an array. Each limit is counted separately and evaluated in order; the first
one exceeded rejects the request, and the `Retry-After` reported is that limit's.

```php
RateLimiter::for('reports', function (Request $request) {
    $caller = $request->user_id() ?: $request->ip();

    return [
        Limit::per_minute(10)->by('minute:' . $caller),
        Limit::per_day(1000)->by('day:' . $caller),
    ];
});
```

**Give each limit a distinct `by()` value.** Two limits segmented by the same value share one
counter, which is almost never what you want. Prefixing, as above, is the simplest way.

---

## 5. Segmenting a limit

`by()` sets what the allowance is counted per:

```php
Limit::per_minute(100)->by($request->ip());
```

Without `by()`, the limit applies to the route as a whole — every caller draws on one shared
allowance, which is what you want for protecting a scarce backend rather than individual callers:

```php
RateLimiter::for('global', function () {
    return Limit::per_minute(1000);
});
```

With no `by()` and no named limiter, an inline limit counts per authenticated user, falling back
to the client address for guests.

`by()` is not restricted to callers. Limiting login attempts per submitted address, so one
account cannot be brute forced from many hosts:

```php
RateLimiter::for('login', function (Request $request) {
    return [
        Limit::per_minute(500),
        Limit::per_minute(3)->by($request->string('email')),
    ];
});
```

---

## 6. Response headers

Every response from a throttled route carries the caller's standing:

| Header | On | Meaning |
| --- | --- | --- |
| `X-RateLimit-Limit` | every response | the allowance for the window |
| `X-RateLimit-Remaining` | every response | attempts left in the window |
| `Retry-After` | 429 only | seconds until the caller may retry |
| `X-RateLimit-Reset` | 429 only | timestamp at which the allowance resets |

Headers on permitted responses are what let a well-behaved client slow down *before* it is
rejected, rather than discovering the wall by hitting it.

A route that declares no limit carries none of these.

---

## 7. Custom rejection responses

`response()` replaces the body of a 429. The callback receives the request and the rate limit
headers, so a custom body can still report them:

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::per_minute(60)->response(function ($request, array $headers) {
        return [
            'error' => 'Slow down',
            'retry_after' => $headers['Retry-After'],
        ];
    });
});
```

The returned value becomes the JSON body. The status stays 429 and the headers are still sent.

---

## 8. Trusted proxies

**Read this before deploying behind a CDN.** It changes who gets limited.

Guests are identified by their network address, read from `REMOTE_ADDR`. Behind Cloudflare, nginx,
or a load balancer, `REMOTE_ADDR` is the *proxy*, and the real client sits in `X-Forwarded-For` or
`CF-Connecting-IP`.

Those headers are ignored by default, and that default is deliberate. A caller can set them to
anything. If they were believed unconditionally, one client could present a different address on
every request, land in a different bucket each time, and never be limited at all — the feature
would be decorative.

So tell the framework which proxies to believe, in `config/app.php`:

```php
'trusted_proxies' => ['192.0.2.10', '198.51.100.0/24'],
```

Exact addresses, CIDR ranges (IPv4 and IPv6), and `'*'` are accepted. Use `'*'` only when
something in front of the application already strips incoming forwarding headers — otherwise it
reintroduces exactly the hole described above.

**Until you configure this, every visitor behind your CDN shares one bucket**, because they all
appear to arrive from the same proxy address. That fails in the safe direction — too restrictive
rather than bypassable — but it is not what you want in production.

`$request->ip()` is a general request method, so it is available outside rate limiting too, and
honours the same rules.

---

## 9. Using the limiter directly

Nothing about the limiter is route specific.

```php
use Framework\Supports\Facades\RateLimiter;

$executed = RateLimiter::attempt('send-message:' . $user_id, 5, function () {
    // Send the message.
});

if (!$executed) {
    return 'Too many messages sent.';
}
```

`attempt()` takes a decay in **seconds** as its fourth argument, defaulting to 60.

The pieces are available separately:

```php
if (RateLimiter::too_many_attempts('send-message:' . $user_id, 5)) {
    $seconds = RateLimiter::available_in('send-message:' . $user_id);

    return 'Try again in ' . $seconds . ' seconds.';
}

RateLimiter::increment('send-message:' . $user_id);
```

**Prefer the value `increment()` returns** when an endpoint may be hit concurrently. It counts and
reports in one operation, rather than reading and then writing:

```php
if (RateLimiter::increment('send-message:' . $user_id) > 5) {
    return 'Too many attempts.';
}
```

Also available: `attempts()`, `remaining()`, `retries_left()`, `available_at()`, and `clear()`.

```php
RateLimiter::clear('send-message:' . $user_id);
```

---

## 10. Configuration

The limiter counts in the default cache store unless told otherwise, in `config/cache.php`:

```php
'default' => 'database',

'limiter' => 'database',
```

Pointing the limiter at its own store matters when the default store is flushed often: clearing a
page cache should not hand every caller a fresh allowance.

In tests, pointing it at `array` keeps counters inside the request.

---

## 11. Concurrency and locks

Counting has to survive simultaneous requests, or a burst slips past the limit. The cache's
`increment()` is not atomic on its own (see [cache.md](cache.md) §13), so the limiter compensates:

- **With a persistent object cache**, the store increments atomically through `wp_cache_incr` and
  the limiter uses it directly.
- **Without one**, the read and write are serialized behind `Cache::lock()`, which is atomic
  through the unique index on the options table.

Both paths are atomic, so limits hold on a default WordPress install, not only on sites running
Redis. Acquisition never blocks; the lock is retried briefly and then the count proceeds anyway,
which is no worse than the cache's own behaviour and never holds up a request.

Two things worth knowing:

- **Windows are fixed, not sliding.** A window opens at the first attempt and closes a decay
  later. A caller can therefore make up to twice the limit across a window boundary — the tail of
  one window plus the head of the next. This matches Laravel.
- **Counters are per-site.** Locks and counters live in the site's own options table, so a
  multisite network limits each site separately.

---

## 12. Where this differs from Laravel

**Method naming.** Multi-word verbs are snake_case, matching the rest of this framework and the
cache before it: `too_many_attempts()`, `available_in()`, `reset_attempts()`, `Limit::per_minute()`.
Single-word verbs are unchanged: `attempt`, `increment`, `remaining`, `clear`, `for`.

**`Limit::after()` is not supported.** Laravel can count only responses matching a predicate, to
stop enumeration attacks by throttling 404s. It needs the counter to be incremented *after* the
response exists, but middleware on a REST route runs inside WordPress's permission callback, which
runs strictly before dispatch, and there is no post-dispatch hook in the site pipeline at all.
Supporting it on REST routes only would leave site routes silently different, so it is deferred to
a change that can add a real post-dispatch hook to both.

**`throttleWithRedis()` has no equivalent**, because there is no separate Redis driver. A site
running a Redis object cache already gets the atomic path automatically.

**Custom responses return a body, not a response object.** `response()` returns the JSON payload
rather than a full response, because a middleware return value is discarded on REST routes. Status
and headers are applied for you.

**`increment()` takes the decay before the amount** — `increment($key, $decay_seconds, $amount)` —
matching Laravel's own signature.
