## Why

The framework can cache but cannot throttle. Any REST endpoint or form-handling site route
is open to unlimited request volume, so a login form, a search endpoint, or a cart mutation
can be hammered without resistance — and WordPress core offers nothing for this. The cache
subsystem shipped in `77c9934` provides the counter storage a rate limiter needs, making
this the natural next capability to build on it.

## What Changes

- Add a `RateLimiter` with Laravel 13.x parity: `attempt`, `too_many_attempts`, `increment`
  (returning the new count), `remaining`, `available_in`, `clear`, `for`, and `limiter`,
  backed by the existing cache.
- Add a `Limit` value object — `per_second`, `per_minute`, `per_minutes`, `per_hour`,
  `per_day`, `none`, `->by()`, `->response()` — including support for a limiter returning an
  **array** of limits evaluated in order.
- Add named limiters registered via `RateLimiter::for()` from a consumer service provider,
  addressed from routes as `throttle:<name>`.
- Add a `ThrottleRequests` middleware enforcing limits on **both** REST and site routes,
  emitting `X-RateLimit-Limit` / `X-RateLimit-Remaining` on every throttled route's response
  and `Retry-After` / `X-RateLimit-Reset` on a 429.
- **Add parameterized middleware to the router**: a middleware alias registry and
  `name:arg1,arg2` parsing, with arguments passed as trailing variadic arguments to
  `handle()`. This is a general routing capability, not throttle-specific. Existing
  fully-qualified-class-name middleware is unaffected.
- Add a `Route::throttle($max, $decay)` builder as typed sugar over `throttle:$max,$decay`.
- **Add an atomic `Cache::lock()`** — `wp_cache_add()` where a persistent object cache
  exists, otherwise `INSERT IGNORE` on `wp_options`, the mechanism WordPress core itself
  uses in `WP_Upgrader::create_lock()`. Required because both `Repository::add()` and
  `Repository::increment()` are documented non-atomic, so a limiter built on them alone
  would silently undercount under concurrent bursts.
- Add an `HttpException` base class and widen the REST permission callback to catch it, so
  middleware can produce an arbitrary HTTP status with headers. The framework currently has
  no `HttpException` and no `abort()` helper at all.
- Add `Request::ip()` with trusted-proxy configuration, and `Request::user_id()`, to
  identify who is being limited. Forwarded headers are honoured **only** behind a configured
  trusted proxy.
- Not included: `Limit::after()` response-based limiting. It requires a post-dispatch hook
  the router does not have, and cannot behave identically across REST and site routes.
  Documented as a deliberate divergence.

## Capabilities

### New Capabilities

- `rate-limiting`: Counting attempts against a keyed limit over a decaying window, named
  limiter definitions, safe client identification, and route throttling with standard
  rate-limit response headers.
- `http-middleware`: Middleware resolution for routes — alias registration, parameterized
  middleware references, and how arguments reach a middleware's `handle()`.
- `http-errors`: Mapping thrown exceptions to HTTP status codes, messages, and headers
  across the REST and site route pipelines.

### Modified Capabilities

- `caching`: Adds atomic locking as a cache primitive. The `add-cache-support` change
  explicitly scoped `Cache::lock()` out; this change reverses that decision because a
  correct rate limiter depends on it.

## Impact

**New code**
- `src/RateLimiting/{RateLimiter,Limit,RateLimiterServiceProvider}.php`
- `src/Middlewares/ThrottleRequests.php`
- `src/Supports/Facades/RateLimiter.php`
- `src/Cache/Lock.php`, `src/Cache/Locks/{ObjectCacheLock,DatabaseLock}.php`,
  `src/Contracts/Lock.php`
- `src/Exceptions/{HttpException,ThrottleRequestsException}.php`
- `docs/rate-limiting.md`

**Modified code**
- `src/Route.php` — middleware alias registry, `name:args` parsing, variadic dispatch in
  `build_middleware_pipeline()`, widened catch in `resolve_permission_callback()`, and the
  `throttle()` builder.
- `src/Cache/CacheManager.php`, `src/Supports/Facades/Cache.php` — `lock()`.
- `src/Cache/CacheServiceProvider.php` — stale-lock GC on the existing `cache_gc` hook.
- `src/Http/Request.php` — `ip()`, `user_id()`.
- `src/{ApiExceptionHandler,SiteExceptionHandler}.php` — `HttpException` handling.
- `src/Application.php`, `src/CoreServiceProvider.php` — provider, alias, `throttle` alias.
- `src/Contracts/Middleware.php` — document the variadic convention (signature unchanged).
- `src/Exceptions/InvalidMiddlewareException.php` — finally thrown, for unknown aliases.
- `example/config/{cache,app}.php` — `limiter` and `trusted_proxies` keys.

**Depends on** the existing cache subsystem (`CacheManager`, `Repository`, `Store`) and
`Database\Connection\Connection` for the SQL lock path.

**WordPress surface**: `wp_cache_add`, `wp_options` via `$wpdb`, `rest_post_dispatch`,
`get_current_user_id`, `status_header`.

**Sequencing note**: `openspec/specs/caching/` does not exist yet — `add-cache-support` is
complete but unarchived. That change should be archived before or alongside this one so the
`caching` delta here lands on a materialized spec.

**No dependencies added.**

**No breaking changes.** Existing FQCN middleware, routes, and cache calls behave exactly as
before.
