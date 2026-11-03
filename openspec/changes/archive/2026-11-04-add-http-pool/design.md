## Context

See proposal.md (Why) and specs/http-client-pool/spec.md (requirements).

State of the code today:

- `Request::send_request()` validates the method, then calls `parse_request_data()`, `prepare_request_url()`, `prepare_request_body()` and `prepare_request_args()`, then `wp_remote_request($url, $args)`, then `new_response()`. Note that `prepare_request_body()` for multipart changes `$this->options['headers']` (the boundary) *before* `prepare_request_args()` reads them, so the build order must stay as it is.
- The `Http` facade (`$is_cacheable = false`) gives a new `Request` for each static call through the container key `client-request`.
- `WP_Http::request()` (read from WP 6.9.4 source, the same as on 6.8) is the behavior that the pool must reproduce. `WpOrg\Requests\Requests::request_multiple()` skips it completely.
- Unit tests run without WordPress. `WpOrg\Requests` is not in `vendor/`, `add_filter()` is a no-op, `apply_filters()` returns the value unchanged, and there is no `do_action()`, `wp_parse_args()`, `get_bloginfo()` or `wp_remote_request()` stub.
- Scoping: kirki-ecommerce's `scoper.config.php` and `example/scoper.config.php` both use `exclude-namespaces: ['~^$~', '/^(?!Framework($|\\\\))/']` with `expose-global-classes`, so `WpOrg\Requests\*` and global `WP_*` classes are never prefixed.

## Goals / Non-Goals

**Goals:**
- The pool and a single request share one request-building path, with no copied code.
- `WP_Http::request()` is copied step by step for each request, so a pooled request is the same as the same request sent alone, apart from timing.
- A test seam lets unit tests check batching, filters and URL checks with no network and no WordPress.

**Non-Goals:**
- A sliding-window scheduler. `request_multiple()` has no concurrency option, so batches are sent one after the other and each batch waits for its slowest request.
- Async/promises and retries. The only change to `Response` is the `failed()` fix for `WP_Error` (decided during apply: the brief's rule 5 assumed `failed()` was already true for a `WP_Error`, but it was not).
- Fixing the framework's singular `reject_unsafe_url` default key (decided: keep it as it is in both paths, and document it).

## Decisions

### D1. Split `send_request()` into `build_request()` + `dispatch()`

- `build_request(string $method, string $url, array $options): array` validates the method and returns `[$url, $args]`. It uses the same four `prepare_*` calls in the same order.
- `dispatch(string $url, array $args)` calls `wp_remote_request()` and wraps the result with `new_response()`.
- `send_request()` becomes `[$url, $args] = $this->build_request(...); return $this->dispatch($url, $args);`.

Both new methods are `protected` with `@since 3.3.0`. A single request makes the same calls, in the same order, with the same values as before. *Alternative considered:* a separate builder class. It was rejected because subclasses and macros already rely on the `prepare_*` methods of `Request`, and moving them is churn with no gain.

### D2. `PooledRequest extends Request` overrides only `dispatch()`

`PooledRequest::dispatch()` stores `$url` and `$args` on the instance and returns `$this`. Every fluent method, `send()` (base URL joining) and `build_request()` are inherited, so rule 1 holds by construction. The verb docblocks say `@return Response`. `PooledRequest` overrides nothing visible, and its class docblock says that verbs return the pooled request in a pool.

A pooled request with no recorded `[$url, $args]` (for example `$pool->as('x')` with no verb call) is a programmer error. `Pool` throws `RuntimeException` naming the key when the callback returns, before anything is sent. *Alternative:* skip it silently. That breaks "every pooled request gets an entry".

*Alternative considered:* a flag on `Request` (`$this->pooled`). It was rejected because it puts pool state on every single request.

### D3. `Pool` owns keys, preflight, batching and transport

`Framework\Http\Client\Pool`:

- `as($key)` checks for duplicates (`InvalidArgumentException`), then creates and registers a `PooledRequest` under the key. Unkeyed requests are registered with append semantics. Registration uses an internal ordered list of `[key, request]`. The append index is the highest integer key used so far + 1, the same as PHP's `$array[]`, so `as(5)` followed by an unkeyed request gives `6`.
- `__call($method, $args)` registers an unkeyed `PooledRequest` and forwards the call to it, so `$pool->timeout(15)->get($url)` works.
- `new_request()` (protected) gives `new PooledRequest()`. This is the extension point, the same pattern as `Request::new_response()`.
- `Request::pool(callable $callback, array $options = [])` validates `concurrency` (`is_int($value) && $value >= 1`, otherwise `InvalidArgumentException`, with default 10). It then builds `$this->new_pool()`, calls the callback, and returns `$pool->send($concurrency)`. `new_pool()` gives `new Pool()`.

`Pool::send(int $concurrency): array` works in this order:

1. Preflight each request in the order added (D4). The result is either a final response value (an array or a `WP_Error`) or a "needs network" entry with the prepared Requests payload.
2. Split the "needs network" entries with `array_chunk($pending, $concurrency, true)`.
3. For each chunk, call `send_batch(array $requests): array`. **This is the test seam.** It is the only place that calls `WpOrg\Requests\Requests::request_multiple($requests)` (inside `mbstring_binary_safe_encoding()`/`reset_mbstring_encoding()`), and it returns the Requests results keyed like its input.
4. Convert each result (D5) and fill it into its slot. Wrap every slot with `Response` and return the array with keys in the order the requests were added.

*Alternative considered:* put the WP_Http copy in a separate `PoolTransport` class. It was rejected for now because it would be a single-use abstraction. `Pool`'s protected methods are already the seam, and consumers can subclass.

### D4. Preflight copies `WP_Http::request()` up to the transport call

For each request with `[$url, $args]`:

1. `$args = wp_parse_args($args)`. Build the defaults array as WordPress does: `method` GET, `timeout` from `http_request_timeout` (5), `redirection` from `http_request_redirection_count` (5), `httpversion` from `http_request_version` ('1.0'), `user-agent` from `http_headers_useragent` (`'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')`), `reject_unsafe_urls` from `http_request_reject_unsafe_urls` (false), `blocking`, `headers`, `cookies`, `body`, `compress`, `decompress`, `sslverify` true, `sslcertificates` `ABSPATH . WPINC . '/certificates/ca-bundle.crt'`, `stream`, `filename`, `limit_response_size`. For HEAD, the default `redirection` is 0. `$args = wp_parse_args($args, $defaults)`.
2. `$args = apply_filters('http_request_args', $args, $url)`. Set `_redirection` if it is not set.
3. `$pre = apply_filters('pre_http_request', false, $args, $url)`. If it is not `false`, it is the final value. **No `http_api_debug` and no `http_response`**, because WordPress returns `$pre` directly.
4. If `wp_kses_bad_protocol` exists: if `reject_unsafe_urls`, then `$url = wp_http_validate_url($url)`. If `$url` is set, then `$url = wp_kses_bad_protocol($url, ['http', 'https', 'ssl'])`.
5. An empty URL or a URL with no scheme gives `WP_Error('http_request_failed', __('A valid URL was not provided.'))` and fires `http_api_debug`.
6. `(new \WP_Http())->block_request($url)` gives `WP_Error('http_request_not_executed', ...)` and fires `http_api_debug`. `block_request()` is public, not static, so an instance is created once for each `send()`.
7. For a stream request: if `filename` is empty, use `get_temp_dir() . basename($url)`. Force `blocking` to true. If `! wp_is_writable(dirname(filename))`, return `WP_Error` and fire `http_api_debug`.
8. Normalize the headers: if they are null, use `[]`. If they are a string, use `WP_Http::processHeaders()`.
9. Build the Requests options as WordPress does: `timeout`, `useragent`, `blocking`, `hooks` = `new \WP_HTTP_Requests_Hooks($url, $args)` with `before_redirect` → `[\WP_Http::class, 'browser_redirect_compatibility']`, and `validate_redirects` when `reject_unsafe_urls`. Then `filename` (stream), `follow_redirects` false or `redirects`, `max_bytes`, `cookies` via `WP_Http::normalize_cookies()`, `verify`/`verifyname` false or `sslcertificates`, `data_format` 'body' for non-GET/HEAD, `verify` filtered by `https_ssl_verify`, and the proxy via `new \WP_HTTP_Proxy()` (`is_enabled() && send_through_proxy($url)` → `new \WpOrg\Requests\Proxy\Http(host:port)` with authentication).
10. The "needs network" entry is `['url' => $url, 'headers' => $headers, 'data' => $body, 'type' => $method, 'options' => $options]`, the per-request shape that `request_multiple()` takes. Keep `$args` and `$url` next to it for D5.

`WP_HTTP_Requests_Hooks` dispatches `requests-{$hook}` actions, so `requests-requests.before_redirect` and the other `requests-*` hooks fire as on the single path.

### D5. Converting results

`request_multiple()` returns either a `WpOrg\Requests\Response` or a `WpOrg\Requests\Exception` for each request. It does not throw for a failure of one request.

- Response → `$http = new \WP_HTTP_Requests_Response($result, $args['filename'])`, `$response = $http->to_array()`, `$response['http_response'] = $http`.
- Exception → `new \WP_Error('http_request_failed', $e->getMessage())`.
- Then `do_action('http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $args, $url)`. If it is a `WP_Error`, it is final. If not `blocking`, use the empty WordPress response array. Otherwise use `apply_filters('http_response', $response, $args, $url)`.

The `'WpOrg\Requests\Requests'` string literal must survive scoping unchanged. With the current configs it does, because the namespace is excluded. Writing it as `\WpOrg\Requests\Requests::class` gives the same value and is clearer, so use that.

### D6. Global symbols are written fully qualified

`use WpOrg\Requests\Requests;` / `use WP_Error;` imports are fine with the current scoper configs. Write global WP classes with the leading backslash or a `use` import, as `Request.php` does for `WP_Http_Cookie`. Verification (task) runs `make scope` and greps the scoped `Pool.php` for `Themeum\\WpOrg` / `Themeum\\WP_` (there must be no matches). The docs tell consumers that a scoper config must not prefix `WpOrg\Requests`.

### D7. Test seam and doubles

- `tests/Support/StubsWordPressFunctions.php`:
  - `add_filter`/`apply_filters` use `$GLOBALS['framework_test_filters'][$hook][$priority][] = [$callback, $accepted_args]` and run the callbacks in priority order. With no callbacks registered, they return the value unchanged, as now.
  - New stubs, each with a `function_exists` guard: `do_action` (records calls in `$GLOBALS['framework_test_did_actions']` and runs callbacks registered through `add_action`, with no consumption, unlike `framework_test_do_action`), `remove_all_filters`, `wp_parse_args`, `get_bloginfo`, `wp_http_validate_url`, `wp_kses_bad_protocol`, `get_temp_dir`, `mbstring_binary_safe_encoding`, `reset_mbstring_encoding`, `wp_remote_request` (records `[$url, $args]`, applies `pre_http_request`, and otherwise returns a canned 200 array), and `WPINC`.
- `TestCase::tearDown()` resets `framework_test_filters` and `framework_test_did_actions`.
- `tests/Support/Http/`: stand-ins for `WP_Http` (`block_request` honoring the `WP_HTTP_BLOCK_EXTERNAL`/`WP_ACCESSIBLE_HOSTS` logic through injectable globals, because constants cannot be undefined between tests), `WP_HTTP_Proxy` (disabled), `WP_HTTP_Requests_Hooks`, and `WP_HTTP_Requests_Response` (`to_array()` from a fake result). A `RecordingPool extends Pool` overrides `send_batch()`: it records each batch's payloads and returns canned results, or an exception object, for each URL. A `RecordingRequest extends Request` overrides `new_pool()` to return `RecordingPool`.
- The WP class stand-ins are loaded only when the real classes are absent (`class_exists` guard), the same as the existing `WP_Error` stub.

*Alternative considered (rejected in grilling):* add `rmccue/requests` to require-dev, or run the tests in the Docker playground.

## Risks / Trade-offs

- [Existing tests relied on `add_filter`/`apply_filters` being no-ops, and src code registers filters during boot in tests] → The registry is reset in `tearDown()`. Run the full `composer test:unit` before and after the stub change, and fix any test that breaks by resetting the filter state, not by weakening the stub.
- [Drift from future `WP_Http::request()` changes] → The docs name the WP version that was copied (6.9.4). Each step in `Pool` has a short "why" comment pointing to the matching WP behavior. Requests that need exact parity in the future can still use single `Http::` calls.
- [Batches wait for their slowest request] → This is acceptable for the target use (about 22 images, concurrency 10, 3 batches). It is documented, and a sliding window is a non-goal.
- [The fsockopen transport has no parallel support] → `request_multiple()` then sends the requests one after the other. Results stay correct, and the docs say so.
- [Test doubles of WP classes can drift from the real ones] → The Docker timing check (task) runs the real WP classes and real Requests end to end.
- [`PooledRequest` verbs return the request, not a `Response`, against the docblock] → This is documented in the `PooledRequest` class docblock and in `docs/http-client.md`.

## Migration Plan

This change only adds API, so no migration is needed. Release 3.3.0. Consumers bump the framework, re-run their scoper, and can then replace sequential loops with `Http::pool()`. To roll back, return the loop to single requests. No data or config migration.
