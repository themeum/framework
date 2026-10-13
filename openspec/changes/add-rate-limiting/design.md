## Context

See proposal.md — Why. The constraints below are what actually shape the approach; each was
verified against the code rather than assumed.

- `Route::build_middleware_pipeline()` (`src/Route.php:1889`) validates a middleware
  reference with `is_subclass_of()` and instantiates it with `new $middleware()`. There is no
  alias map, no argument parsing, and no container resolution. A `'throttle:60,1'` string
  fails the `is_subclass_of()` check today.
- On REST routes the pipeline runs inside `permission_callback`
  (`src/Route.php:1917`) with destination `fn ($request) => true`, and **its return value is
  discarded**. Only `AuthorizationException` is caught; anything else escapes uncaught.
  Middleware therefore cannot return a response — it can only throw.
- The site pipeline (`src/Route.php:1655`) differs: the return value *is* assigned back to
  `$request`, then `expose()` and `validate_request()` are called on it.
- `Repository::increment()` and `Repository::add()` are both documented non-atomic
  (`src/Cache/Repository.php:443`, `:513`). Only `DatabaseStore` with a persistent object
  cache reaches an atomic `wp_cache_incr` (`src/Cache/Stores/DatabaseStore.php:301`).
- There is no `HttpException`, no `abort()` helper, and no `Request::ip()` anywhere in `src/`.
  `Response::TOO_MANY_REQUESTS` exists but is unused.
- PHP 7.4 target. `phpcs/Framework/Sniffs` enforces snake_case methods, no `private`,
  `static::` over `self::`, and a docblock on every method and property.

Target parity is Laravel 13.x, confirmed against the published 13.x documentation rather
than recollection. Two details differ from earlier Laravel and from initial assumptions:
13.x documents **`increment()`**, not `hit()`, and it presents checking `increment()`'s
**return value** as the concurrency-correct pattern.

## Goals / Non-Goals

**Goals:**

- A limiter API a Laravel developer can use without relearning it, subject to the
  repository's snake_case convention.
- Throttling that behaves the same on REST routes and site routes, from the caller's
  perspective.
- Correct counting under concurrent bursts on a **default** WordPress install, not only on
  sites running Redis.
- A parameterized-middleware mechanism general enough that `throttle` is merely its first
  consumer.
- No behaviour change for any existing route, middleware, or cache call.

**Non-Goals:**

- Distributed or cross-host coordination. Locks are per-database.
- A general-purpose blocking lock. Acquisition never waits.
- Response-based limiting (`after()`); see Decision 9.
- Replacing the non-atomic `increment()`/`add()` primitives themselves. They keep their
  documented semantics; the limiter compensates with a lock.

## Decisions

### 1. Three layers, mirroring the cache

`RateLimiter` (registry + counting) → `Limit` (value object) → the framework cache for
storage. This repeats the `CacheManager → Repository → Store` and
`DatabaseManager → Connection → QueryCompiler` shape already established in the codebase,
so the file layout and `__call` forwarding conventions carry over unchanged.

*Alternatives:* a single flat `RateLimiter` holding both registry and counting. *Rationale:*
`Limit` needs to be a passable, inspectable value (limiters return one or an array of them,
and `->by()`/`->response()` hang off it), so it has to be its own type regardless.

### 2. Counting is guarded by a lock, not made atomic

The limiter serializes its read-and-increment with `Cache::lock()` rather than requiring an
atomic store. Where the store *does* offer an atomic counter, the lock is skipped and the
atomic path is used directly.

*Alternatives:* (a) accept best-effort counting and document the leak, as most WordPress
plugins do; (b) add an atomic counter to the `Store` contract backed by a custom SQL table.
*Rationale:* (a) means a "60/min" limit can admit materially more under a burst, which is
exactly the case the feature exists to handle. (b) contradicts the cache feature's
"transients, not SQL" decision for its main storage path and would require a new table plus
migration. A lock is a narrower addition and is independently useful.

### 3. Locks are atomic via `wp_cache_add()`, falling back to `INSERT IGNORE`

`ObjectCacheLock` uses `wp_cache_add()`, atomic on Redis/Memcached, when
`wp_using_ext_object_cache()` reports a drop-in. Otherwise `DatabaseLock` issues
`INSERT IGNORE INTO {$wpdb->options}` with `autoload = 'no'`, atomic by virtue of the unique
index on `option_name`.

*Alternatives:* build the lock on `Repository::add()`. *Rationale:* `add()` is a read
followed by a write, so two callers can both "acquire" the same lock — a lock that appears
correct and silently is not, which is worse than no lock because it invites trust it hasn't
earned. `INSERT IGNORE` on the options table is precisely the mechanism WordPress core uses
for its own locking in `WP_Upgrader::create_lock()`, so it is well-precedented rather than
novel. The SQL goes through `Database\Connection\Connection`, which already wraps
`global $wpdb`.

### 4. Locks carry a mandatory expiry and an owner token

Every acquisition sets a TTL, so a request that dies mid-hold cannot block a key forever.
The stored value is a random owner token; release deletes only on a token match, so a caller
whose lock already expired and was re-acquired cannot release the new holder's lock. Expired
rows are reclaimed by the `cache_gc` hook `CacheServiceProvider` already schedules.

*Alternatives:* no expiry, relying on release. *Rationale:* PHP-FPM workers are killed by
timeouts routinely; a lock without expiry converts one dead request into a permanently
throttled key.

### 5. No blocking acquisition

`get()` returns immediately. There is no `block()` with sleep-and-retry.

*Alternatives:* full Laravel lock parity including `block()`. *Rationale:* a blocking wait
holds a PHP-FPM worker for its duration; under the concurrency a rate limiter exists to
absorb, that converts a throttling event into worker-pool exhaustion — the limiter becoming
the outage. The limiter only needs a short non-blocking acquire, and a caller that loses the
race can safely fall back to a plain read.

### 6. Middleware arguments arrive as trailing variadic parameters

`build_middleware_pipeline()` splits a reference on its first `:`, resolves the name through
an alias registry, splits the remainder on `,`, and calls
`handle($request, $next, ...$params)`. `ThrottleRequests` declares
`handle(Request $request, callable $next, ...$params)`.

*Alternatives:* pass arguments to the constructor via `new $middleware(...$params)`.
*Rationale:* PHP permits an implementation to add optional or variadic parameters beyond its
interface's signature, so `Contracts\Middleware` needs **no** change and every existing
middleware stays valid — a constructor change would instead alter how *all* middleware is
instantiated. It also matches Laravel, so the convention is already familiar.

The `is_subclass_of()` guard is kept, applied to the *resolved* class rather than the raw
string, so an FQCN reference behaves exactly as before. Unknown aliases throw the
`InvalidMiddlewareException` that already exists in `src/Exceptions/` and has never been
thrown.

### 7. A new `HttpException`, caught in the permission callback

`HttpException` carries status, message, headers, and an error code.
`ThrottleRequestsException` extends it with 429 and the rate limit headers.
`resolve_permission_callback()` gains a `catch (HttpException)` producing a `WP_Error` with
the correct code and status; `ApiExceptionHandler` and `SiteExceptionHandler` learn the same
type.

*Alternatives:* (a) subclass `AuthorizationException` so the existing catch handles it, with
no routing change; (b) check limits at dispatch time inside `resolve_route()` instead of in
middleware. *Rationale:* (a) ships a 429 labelled `rest_forbidden` with no `Retry-After`,
which is a poor API and misleads clients. (b) would make `throttle` run after authorization
and behave unlike every other middleware, breaking the mental model. (b) also cannot be
declared on a group. The `HttpException` type is missing from the framework generally, so
adding it pays down existing debt rather than adding surface for one feature.

### 8. Headers are attached through `rest_post_dispatch`

Because the REST pipeline discards middleware return values, headers on a **successful**
response cannot be set from middleware. The limiter records the computed limit and remaining
count on the request during the middleware pass, and a `rest_post_dispatch` filter copies
them onto the outgoing response. Rejection headers travel on the exception instead. Site
routes use `status_header()` via `SiteExceptionHandler`, which already exists.

*Alternatives:* emit headers only on 429s. *Rationale:* headers on success are what let a
client pace itself and back off *before* being rejected; omitting them means every
well-behaved client still has to hit the wall to discover it.

### 9. `after()` is deliberately deferred

Laravel 13's `->after()` counts only responses matching a predicate. It requires the counter
increment to happen *after* dispatch, but the REST pipeline runs middleware strictly before
dispatch and there is no post-dispatch hook in either pipeline.

*Alternatives:* implement it for REST only via `rest_post_dispatch`. *Rationale:* that
yields a feature present on REST routes and silently absent on site routes — an inconsistency
more confusing than a documented omission. Building a real post-dispatch hook into both
pipelines is a routing-architecture change larger than the rate limiter itself and belongs in
its own change. Documented in the "differs from Laravel" section.

### 10. Client identity resolves through a general `Request::ip()`, trusting no proxy by default

`Request::ip()` returns `REMOTE_ADDR` unless the connecting address matches
`config('app.trusted_proxies')`, in which case `X-Forwarded-For` (leftmost untrusted entry)
or `CF-Connecting-IP` is used. CIDR ranges and `'*'` are supported. Default is an empty list.

*Alternatives:* (a) read `X-Forwarded-For` whenever present; (b) keep the resolution private
to the limiter. *Rationale:* (a) is a security hole — a caller forging the header mints
unlimited distinct buckets and bypasses the limiter entirely, so the feature would be
decorative. Defaulting to no trust means the limiter is merely *coarse* behind an
unconfigured CDN (all traffic sharing a bucket) rather than *bypassable*, which is the right
direction to fail. (b) buries a facility useful framework-wide and guarantees it gets
rebuilt later.

### 11. Fixed window, matching Laravel

Each key holds a counter plus a timer key carrying the window's reset timestamp; both expire
together. A window opens on the first attempt and closes a fixed decay later.

*Alternatives:* a sliding window or token bucket, both smoother across window boundaries.
*Rationale:* parity is the stated goal, and a fixed window is what `Retry-After` and
`X-RateLimit-Reset` describe naturally. The boundary burst (up to 2× the limit across a
window edge) is Laravel's documented behaviour too.

### 12. `throttle:60,1` means 60 attempts per 1 **minute**

The second argument is minutes, as in Laravel, while `Limit::per_minutes()` and the internal
decay are seconds. The middleware converts.

*Rationale:* the string form is the surface developers copy from Laravel documentation;
silently reinterpreting its second argument as seconds would be a trap.

### 13. Naming follows the cache precedent

Single-word Laravel verbs are kept verbatim (`attempt`, `increment`, `remaining`, `clear`,
`for`); multi-word verbs are snake_cased (`too_many_attempts`, `available_in`,
`Limit::per_minute`).

`for` is named `for` on the manager, exactly as in Laravel. PHP's context-sensitive lexer
(since 7.0) permits reserved words as method names, so `public function for($name, Closure
$callback)` is valid on the 7.4 target — only a *plain function* named `for` would be
illegal. No workaround is needed.

*Rationale:* this is the convention the cache feature set one commit earlier, and
`SnakeCaseMethod` enforces it regardless.

### 14. The limiter's store is separately configurable

`config('cache.limiter')` selects the store, falling back to `cache.default`, mirroring
Laravel's `limiter` key.

*Rationale:* it lets a site keep page caching on `file` while counters sit on `database`,
and lets tests point the limiter at `array` without touching the default store.

## Risks / Trade-offs

- **The lock adds a write to `wp_options` on every throttled request on sites without an
  object cache.** → The row is `autoload = 'no'`, short-lived, and reclaimed by the existing
  `cache_gc` sweep. Sites with a persistent object cache never take this path. Throttling is
  opt-in per route, so unthrottled routes are unaffected.
- **Reversing the cache feature's "no locks" decision.** → It is an addition, not a
  redefinition: `increment()` and `add()` keep their documented non-atomic semantics and the
  existing `Counters` requirement is untouched. The reversal is recorded explicitly in the
  `caching` delta.
- **Touching `build_middleware_pipeline()` risks existing middleware.** → FQCN references
  contain no `:`, so they take an unchanged code path, and the `is_subclass_of()` guard is
  preserved. `tests/Unit/RouteMiddlewareTest.php` already covers the existing behaviour and
  must keep passing unmodified.
- **Widening the `permission_callback` catch could mask errors.** → Only `HttpException` is
  added; every other exception keeps escaping exactly as it does today.
- **Fixed windows permit a 2× burst across a boundary.** → Matches Laravel; documented.
- **Counting still degrades if a caller bypasses the lock path.** → The limiter is the only
  writer of its own counters, and it always takes either the lock or the atomic path.
- **Behind an unconfigured CDN all traffic shares one bucket**, so one proxy address could
  exhaust a limit for every visitor. → This is the safe failure direction (over-restrictive,
  not bypassable), and `docs/rate-limiting.md` leads with trusted-proxy configuration.

## Migration Plan

Additive throughout; no migration required and nothing to roll back at the data layer. The
lock's options rows are self-expiring, so reverting the code leaves at most a handful of
short-lived rows that the next `cache_gc` sweep removes.

`openspec/specs/caching/` does not yet exist because `add-cache-support` is complete but
unarchived. Archive that change before or alongside this one so this `caching` delta applies
to a materialized spec.

## Open Questions

None. The design questions were resolved before this document was written; `after()` is a
recorded deferral (Decision 9) rather than an open question.
