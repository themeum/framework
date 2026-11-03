## Why

The `Http` facade can only send one request at a time. kirki-ecommerce downloads about 22 sample product images from S3 with `Http::timeout(15)->get($url)`. Each request takes about 2 s, so the full download takes about 45 s. If the requests run in parallel, the total time is about the time of the slowest request (2–3 s). WordPress already ships a parallel transport (`WpOrg\Requests\Requests::request_multiple()`), but nothing in the framework gives access to it.

## What Changes

- Add `Http::pool(callable $callback, array $options = [])`, modelled on Laravel's `Http::pool()` with snake_case names. The callback receives a `Framework\Http\Client\Pool`. It records requests with `$pool->as($key)->...->get($url)` (or `$pool->...` with no key). `pool()` returns `array<string|int, Response>` in the order the requests were added.
- Add `Framework\Http\Client\Pool` and `Framework\Http\Client\PooledRequest`. `PooledRequest` extends `Request`, so every fluent method works unchanged. Its verb methods record the built request and do not send it.
- Split `Request::send_request()` into a build step (final `$url` and `$args`) and a dispatch step. A single request still dispatches through `wp_remote_request()` and behaves exactly as before.
- The pool does for each request what `WP_Http::request()` does: it merges the defaults, applies the filters (including `pre_http_request` and `http_request_args`), checks the URL, converts the arguments to Requests options, builds the same response shape, and fires `http_api_debug` and `http_response`. It then sends the requests that still need the network with `request_multiple()`, in batches of `concurrency` (default 10).
- A failed request never throws and never affects the other requests. Its entry wraps a `WP_Error`. Programmer errors throw before anything is sent: a duplicate key, an invalid `concurrency`, an invalid method or an invalid body format.
- Fix `Response::failed()` so that it returns `true` when the wrapped value is a `WP_Error`. Today it returns `false`, because the status reads as `0`. This also changes single requests: `failed()`/`on_error()` now fire on transport errors.
- Add the `@method static array pool(...)` line to the `Http` facade docblock.
- Add `docs/http-client.md` (pool only), a new `CHANGELOG.md` with a `3.3.0` entry, and bump `composer.json` to `3.3.0`.
- Test support: the `add_filter`/`apply_filters` stubs will store and run callbacks, and new doubles stand in for the WP HTTP classes and the Requests batch call.

No breaking changes. The change adds API. The only change to existing behavior is the `failed()` fix above. Nothing else on the single-request path changes.

## Capabilities

### New Capabilities
- `http-client-pool`: Concurrent HTTP requests through `Http::pool()`. Covers the pool API and keys, response ordering and errors, `concurrency` batching, and the WordPress HTTP API behavior (filters, short-circuit, URL checks, response shape, hooks) that a pooled request must keep.

### Modified Capabilities
<!-- None: there is no existing spec for the single-request client, and its behavior does not change. -->

## Impact

- **Code**: `src/Http/Client/Request.php` (split `send_request()`, add `pool()`), `src/Http/Client/Response.php` (`failed()` fix), new `src/Http/Client/Pool.php` and `src/Http/Client/PooledRequest.php`, and `src/Supports/Facades/Http.php` (docblock).
- **Runtime dependency**: `WpOrg\Requests` (bundled with WordPress since 6.2; the consumer requires 6.8 or later), `WP_Http`, `WP_HTTP_Proxy`, `WP_HTTP_Requests_Response`, `WP_HTTP_Requests_Hooks` and `WP_Error`. No new Composer dependency.
- **Scoping**: these symbols must stay global after php-scoper. The scoper configs of kirki-ecommerce and of `example/` already exclude every namespace except `Framework`. A verification task checks this in the scoped output.
- **Tests**: `tests/Support/StubsWordPressFunctions.php` (filters now run callbacks; reset in `TestCase::tearDown()`), new doubles in `tests/Support/Http/`, and new `tests/Unit/Http/Client/` tests.
- **Docs and release**: `docs/http-client.md`, `CHANGELOG.md`, and `composer.json` version 3.3.0.
