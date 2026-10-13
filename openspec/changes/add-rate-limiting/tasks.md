## 1. Atomic locks in the cache

- [x] 1.1 Create `src/Contracts/Lock.php` declaring `get($callback = null)`, `release()`, and `owner()`, following the file-header and docblock conventions of `src/Contracts/Store.php`
- [x] 1.2 Create `src/Cache/Lock.php` as an abstract base holding the lock name, TTL in seconds, and a random owner token generated with `wp_generate_password(20, false)`; implement `get(Closure $callback = null)` to acquire, run the callback in a `try`/`finally` that releases, and return the callback result or the boolean acquisition outcome
- [x] 1.3 Create `src/Cache/Locks/ObjectCacheLock.php` acquiring via `wp_cache_add($key, $owner, $group, $seconds)` and releasing only when `wp_cache_get()` matches the owner token
- [x] 1.4 Create `src/Cache/Locks/DatabaseLock.php` acquiring via `INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')`, storing `owner|expires_at` as the value; treat a row whose expiry has passed as free by deleting it and retrying the insert once. Statements are issued through the `DB` facade (`DB::insert()`, `DB::select()`, `DB::delete()`, `DB::get_db()` for the table name), matching `Cache\Stores\DatabaseStore`, which already reaches for the same facade; a failed statement is caught and degrades to a failed acquisition rather than raising
- [x] 1.5 Implement `DatabaseLock::release()` to delete only when the stored owner token matches, so a stale holder cannot release a re-acquired lock
- [x] 1.6 Add `CacheManager::lock(string $name, int $seconds = 60)` selecting `ObjectCacheLock` when `wp_using_ext_object_cache()` is true and `DatabaseLock` otherwise, honouring the store prefix for key naming
- [x] 1.7 Add `@method static` docblock lines for `lock()` to `src/Supports/Facades/Cache.php`
- [x] 1.8 Extend the `cache_gc` sweep in `src/Cache/CacheServiceProvider.php` to delete expired lock rows from `wp_options`

## 2. Parameterized middleware in the router

- [x] 2.1 Add a static `$middleware_aliases` registry to `src/Route.php` with `Route::middleware_alias(string $name, string $class)` and a resolver that returns the class for a name or `null`
- [x] 2.2 Rewrite the closure inside `Route::build_middleware_pipeline()` (`src/Route.php:1889`) to split the reference on its first `:`, resolve the name through the alias registry (falling back to treating it as a class name), `explode(',', ...)` the remainder, and invoke `handle($request, $next, ...$params)`
- [x] 2.3 Keep the `is_subclass_of()` guard, applied to the *resolved* class, and throw the existing `src/Exceptions/InvalidMiddlewareException.php` for an unresolvable name instead of the bare `InvalidArgumentException`
- [x] 2.4 Document the trailing-variadic-argument convention in the docblock of `src/Contracts/Middleware.php` without changing its signature
- [x] 2.5 Verify group middleware still merges correctly through `Route::apply_group_options()` when the declaration carries arguments — covered by `test_group_middleware_carries_its_arguments_to_every_route` and `test_group_and_route_middleware_both_run_in_order`

## 3. HTTP exceptions

- [x] 3.1 Create `src/Exceptions/HttpException.php` extending `RuntimeException`, carrying status, message, headers array, and an `error_code()` used as the `WP_Error` code
- [x] 3.2 Create `src/Exceptions/ThrottleRequestsException.php` extending `HttpException`, defaulting to `Response::TOO_MANY_REQUESTS` with an `error_code()` of `rest_too_many_requests`
- [x] 3.3 Add a `catch (HttpException $exception)` branch to `Route::resolve_permission_callback()` (`src/Route.php:1917`) returning `new WP_Error($exception->error_code(), $exception->getMessage(), ['status' => $exception->get_status()])`, placed before the existing `AuthorizationException` catch
- [x] 3.4 Teach `src/ApiExceptionHandler.php` to render `HttpException` with its status and headers, ahead of the existing `fallback_response()` path
- [x] 3.5 Teach `src/SiteExceptionHandler.php` to map `HttpException` to its status, emitting its headers before `wp_die()`
- [x] 3.6 Clamp an out-of-range status to 500 in both handlers

## 4. Request identity

- [x] 4.1 Add `Request::ip()` to `src/Http/Request.php` returning `$_SERVER['REMOTE_ADDR']`, consulting `X-Forwarded-For` (leftmost entry not itself a trusted proxy) and `CF-Connecting-IP` only when the connecting address matches `config('app.trusted_proxies')`
- [x] 4.2 Implement trusted-proxy matching supporting exact addresses, CIDR ranges for IPv4 and IPv6, and the `'*'` wildcard; default to an empty list when unconfigured
- [x] 4.3 Add `Request::user_id()` wrapping `get_current_user_id()`, returning `null` for guests
- [x] 4.4 Ensure `make_from_http()` and `make_request()` both resolve `ip()` identically — `make_from_http()` stores the server array; `make_request()` (REST) leaves it empty and `ip()` falls back to `$_SERVER`, which is the same data

## 5. The rate limiter

- [x] 5.1 Create `src/RateLimiting/Limit.php` with static `per_second`, `per_minute`, `per_minutes`, `per_hour`, `per_day`, and `none`, plus `by($key)` and `response(Closure $callback)`; hold max attempts, decay seconds, key, and response callback
- [x] 5.2 Create `src/RateLimiting/RateLimiter.php` resolving its store from `config('cache.limiter')` falling back to `config('cache.default')`
- [x] 5.3 Implement `increment($key, $amount = 1)` returning the resulting count, writing the timer key holding the window reset timestamp on first attempt, and taking `Cache::lock()` around the read-and-write unless the store reports an atomic counter
- [x] 5.4 Implement `attempts`, `too_many_attempts($key, $max)`, `remaining($key, $max)`, `available_in($key)` from the timer key, and `clear($key)` removing both counter and timer
- [x] 5.5 Implement `attempt($key, $max, Closure $callback, $decay = 60)` returning `false` when exhausted and the callback result or `true` otherwise
- [x] 5.6 Implement `for($name, Closure $callback)` and `limiter($name)` for the named-limiter registry
- [x] 5.7 Create `src/RateLimiting/RateLimiterServiceProvider.php` binding `RateLimiter` as a singleton
- [x] 5.8 Register the provider in `Application::register_base_service_providers()` (`src/Application.php:291`) immediately after `CacheServiceProvider`, and add the `'limiter' => RateLimiter::class` alias in `register_base_aliases()`
- [x] 5.9 Create `src/Supports/Facades/RateLimiter.php` modelled on `src/Supports/Facades/Cache.php`, with `@method static` lines covering the full surface

## 6. Throttle middleware and route integration

- [x] 6.1 Create `src/Middlewares/ThrottleRequests.php` implementing `Framework\Contracts\Middleware` with `handle(Request $request, callable $next, ...$params)`
- [x] 6.2 Resolve the params: a numeric first argument means an inline `Limit::per_minutes($decay, $max)`; a non-numeric one names a registered limiter, resolved via `is_numeric()` and raising `InvalidArgumentException` for an unknown name
- [x] 6.3 Normalize a limiter's return to an array and evaluate each `Limit` in order, rejecting on the first exceeded one and skipping counter state entirely for `Limit::none()`
- [x] 6.4 Build the throttle key from the limit's `by` value when set, otherwise the authenticated user id, otherwise `Request::ip()`, hashed and combined with the route signature
- [x] 6.5 Throw `ThrottleRequestsException` carrying `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0`, and `X-RateLimit-Reset` when a limit is exceeded, or return the limit's declared custom response when one is set — **note:** the callback returns a body rather than a response object, carried on the exception as a payload, because a middleware return value is discarded on REST routes
- [x] 6.6 Record the computed limit and remaining count on the request for permitted requests, and add a `rest_post_dispatch` filter copying them onto the outgoing response as `X-RateLimit-Limit` and `X-RateLimit-Remaining`
- [x] 6.7 Register the `throttle` alias — **deviation:** registered in `RateLimiterServiceProvider::boot()` rather than `CoreServiceProvider`, so the alias sits beside the limiter it depends on and is registered before any route file is included
- [x] 6.8 Add `Route::throttle($max, $decay = 1)` appending `"throttle:{$max},{$decay}"` to the route's middleware, returning `$this` for chaining
- [x] 6.9 Confirm the site pipeline surfaces `ThrottleRequestsException` through `SiteExceptionHandler` as a 429 — covered by `test_the_site_handler_maps_a_throttle_rejection_to_429`; the exception is thrown from middleware so the pipeline never returns and `expose()` is not reached

## 7. Configuration

- [x] 7.1 Add a `'limiter'` key to `example/config/cache.php` with an explanatory comment
- [x] 7.2 Add a `'trusted_proxies' => []` key to `example/config/app.php` documenting the CIDR and `'*'` forms and the security reason for the empty default

## 8. Tests

- [x] 8.1 Add `wp_options`-backed stubs to `tests/Support/StubsWordPressFunctions.php` so the `INSERT IGNORE` lock path is exercisable, extending the existing `FakeWpdb` double
- [x] 8.2 Create `tests/Unit/RateLimiting/RateLimiterTestCase.php` resetting limiter and cache globals, modelled on `tests/Unit/Cache/CacheTestCase.php`
- [x] 8.3 Create `tests/Support/RateLimiting/FrozenRateLimiter.php` using the `FreezesTime` trait so decay is tested without `sleep()`
- [x] 8.4 Test attempt counting, fixed-window decay and reset, `remaining`, `available_in`, and `clear`
- [x] 8.5 Test that `increment()` returns the resulting count and honours an explicit amount
- [x] 8.6 Test named limiters: registration, resolution by name, per-request variation, and the error for an unknown name
- [x] 8.7 Test multiple limits per limiter — order of evaluation, first-exceeded-wins, and independent counters
- [x] 8.8 Test `Limit::none()` never rejects and writes no counter state
- [x] 8.9 Test lock behaviour: only one of two simultaneous acquisitions succeeds, expiry frees an abandoned lock, a stale holder cannot release a re-acquired lock, and the callback form releases after a raised exception
- [x] 8.10 Test both lock backends — `wp_cache_add()` with `wp_using_ext_object_cache()` stubbed true, and the `INSERT IGNORE` path with it false
- [x] 8.11 Test `Request::ip()`: forwarded headers ignored with no trusted proxies, honoured behind a configured one, CIDR and wildcard matching, and that forged headers cannot multiply allowances
- [x] 8.12 Test middleware argument parsing: no-colon references, one argument, several arguments, a colon inside the argument list, unknown aliases, and that existing FQCN middleware is unchanged
- [x] 8.13 Test the 429 shape and headers on a REST route, and on a site route through `SiteExceptionHandler`
- [x] 8.14 Test that permitted responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining` and that unthrottled routes carry neither
- [x] 8.15 Test a limit's custom rejection response is used and still carries the rate limit headers
- [x] 8.16 Confirm `tests/Unit/RouteMiddlewareTest.php` passes unmodified

## 9. Documentation

- [x] 9.1 Write `docs/rate-limiting.md` mirroring the structure of `docs/cache.md`: scope paragraph, numbered table of contents, then `---`-separated numbered sections covering quick start, inline limits, named limiters, multiple limits, segmenting, response headers, custom responses, trusted proxies, configuration, and CLI-free verification
- [x] 9.2 Include a "Where this differs from Laravel" section covering the deferred `after()`, the snake_case verb naming, and minutes-versus-seconds in `throttle:60,1`
- [x] 9.3 Lead the trusted-proxy section with the security rationale, since the safe default is the surprising one behind a CDN
- [x] 9.4 Append a locks section to `docs/cache.md` documenting `Cache::lock()`, the mandatory expiry, non-blocking acquisition, and the two backends
- [x] 9.5 Update `docs/cache.md` §13 so it no longer states that locking is absent by design
- [x] 9.6 Add a throttled route and a named limiter to `example/routes/api.php` and `example/app/Providers/TestServiceProvider.php` as runnable references

## 10. Verification

- [x] 10.1 Run `composer phpcs` and resolve every sniff violation, particularly `SnakeCaseMethod`, `NoPrivate`, and the docblock sniffs
- [x] 10.2 Run `composer test:unit` and confirm the whole suite passes, including the pre-existing cache and routing tests
- [ ] 10.3 Run `vendor/bin/phpstan analyse` at the configured level 5 against `src` — **not run:** `phpstan.neon.dist` exists but phpstan is not in `require-dev` and is not installed. Needs `composer require --dev phpstan/phpstan` first
- [ ] 10.4 Exercise `example/` end to end: confirm via `curl -i` that a route throttled at 3 per minute returns 429 with `Retry-After` and `X-RateLimit-Reset` on the fourth request, that the first three carry `X-RateLimit-Remaining` counting down, and that the window resets after a minute — **not run:** needs the Docker WordPress stack (`make up`). Routes are wired in `example/routes/api.php` (`/ping-limited`, `/uploads`) ready to exercise
- [ ] 10.5 Exercise a throttled site route in `example/routes/site.php` and confirm it produces a 429 rather than rendering — **not run:** needs the running stack
- [ ] 10.6 Re-run the REST check with a persistent object cache drop-in installed to exercise the `wp_cache_add()` lock path — **not run:** needs the running stack. The path is covered by unit tests in `tests/Unit/RateLimiting/LockTest.php`
- [x] 10.7 Run `openspec validate add-rate-limiting --strict`

## Notes

- 8.1 uses a purpose built `tests/Support/RateLimiting/LockWpdb.php` rather than extending
  `FakeWpdb`, which only understands the DELETE-by-LIKE statement the cache sweep issues.
- 9.6 also added a second named limiter (`reports`) to the example provider, showing the
  multiple-limits form.
- `composer phpcs` reports zero errors in the changed files. The two remaining warnings are
  pre-existing line-length warnings in `src/View/TemplateEngine.php`, which this change did not
  touch.
