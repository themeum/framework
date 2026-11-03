## 1. Baseline and test support

- [x] 1.1 Run `composer phpcs && composer test:unit` on a clean tree, and record the pass count as the baseline
- [x] 1.2 Make the `add_filter`/`apply_filters` stubs in `tests/Support/StubsWordPressFunctions.php` store callbacks in `$GLOBALS['framework_test_filters']` and run them in priority order, honoring `$accepted_args`. With no callbacks, the value must come back unchanged. Add `remove_all_filters`
- [x] 1.3 Add guarded stubs: `do_action` (records each call in `$GLOBALS['framework_test_did_actions']` and runs `add_action` callbacks without consuming them), `wp_parse_args`, `get_bloginfo`, `wp_http_validate_url`, `wp_kses_bad_protocol`, `get_temp_dir`, `mbstring_binary_safe_encoding`, `reset_mbstring_encoding`, `wp_remote_request` (records `[$url, $args]`, applies `pre_http_request`, and otherwise returns a canned 200 response array), and a `WPINC` constant
- [x] 1.4 Reset `framework_test_filters` and `framework_test_did_actions` in `TestCase::tearDown()`
- [x] 1.5 Re-run `composer test:unit`, and confirm that the baseline from 1.1 still passes. Fix any breakage by resetting state, not by weakening the stubs
- [x] 1.6 Add `class_exists`-guarded stand-ins in `tests/Support/Http/` for `WP_Http` (`block_request()` driven by test globals that mirror `WP_HTTP_BLOCK_EXTERNAL`/`WP_ACCESSIBLE_HOSTS`, plus `normalize_cookies`, `processHeaders`, `browser_redirect_compatibility`, `validate_redirects`), `WP_HTTP_Proxy` (disabled by default), `WP_HTTP_Requests_Hooks` (records `register()` calls), and `WP_HTTP_Requests_Response` (`to_array()` from a fake result object). Also add a fake Requests response and exception for the doubles

## 2. Split the single-request path

- [x] 2.1 Add `protected function build_request(string $method, string $url, array $options = [])` to `src/Http/Client/Request.php`. It does the method validation and the four `prepare_*` calls in their current order, and returns `[$url, $args]` (`@since 3.3.0`)
- [x] 2.2 Add `protected function dispatch(string $url, array $args)`, which calls `wp_remote_request()` and returns `new_response()` (`@since 3.3.0`)
- [x] 2.3 Reduce `send_request()` to `build_request()` + `dispatch()`. The `@throws` docblock moves to `build_request()`
- [x] 2.4 Add a characterization test in `tests/Unit/Http/Client/RequestDispatchTest.php`. For GET with a query, POST JSON, form, multipart (boundary header present), `with_body()`, base URL joining, and an invalid method throwing, it asserts the `[$url, $args]` that the `wp_remote_request` stub received

## 3. Pool API

- [x] 3.1 Create `src/Http/Client/PooledRequest.php` (extends `Request`). `dispatch()` stores `$url`/`$args` and returns `$this`. Add `is_recorded()` and a getter for the recorded pair. The class docblock says that verbs return the pooled request in a pool
- [x] 3.2 Create `src/Http/Client/Pool.php` with an ordered registry: `as(string|int $key)` (PHP 7.4: an untyped param with a `@param string|int` docblock; throws `InvalidArgumentException` on a duplicate key), `__call()` registering an unkeyed request with append semantics (highest integer key + 1), and `protected new_request()`
- [x] 3.3 Add `public function pool(callable $callback, array $options = [])` and `protected function new_pool()` to `Request`. Validate `concurrency` (default 10; anything that is not an `int >= 1` throws `InvalidArgumentException` before the callback runs), run the callback, and return `$pool->send($concurrency)`
- [x] 3.4 In `Pool::send()`, throw `RuntimeException` naming the key if any registered request has no verb call. Return `[]` for an empty pool without calling the seam
- [x] 3.6 Make `Response::failed()` return true when the wrapped value is a `WP_Error`. Leave `status()`, `client_error()` and `server_error()` unchanged. Add a test in `tests/Unit/Http/Client/ResponseTest.php` covering `failed()`/`on_error()` for a `WP_Error`, and that a 200/404/500 keep their current results
- [x] 3.5 Add `@method static array pool(callable $callback, array $options = [])` to `src/Supports/Facades/Http.php`

## 4. WordPress HTTP API parity in the pool

- [x] 4.1 Implement the preflight in `Pool` (design D4 steps 1–8): defaults with the five default filters and the HEAD redirection rule, `http_request_args`, `_redirection`, a `pre_http_request` short-circuit (with no debug action and no response filter), `wp_http_validate_url` when `reject_unsafe_urls`, `wp_kses_bad_protocol`, the scheme check, `WP_Http::block_request()`, stream filename and writability, and header normalization. Fire `http_api_debug` on each early `WP_Error`, as WordPress does
- [x] 4.2 Implement the Requests options build (D4 step 9): `timeout`, `useragent`, `blocking`, `WP_HTTP_Requests_Hooks` with the `browser_redirect_compatibility` and conditional `validate_redirects` registrations, `filename`, `follow_redirects`/`redirects`, `max_bytes`, `cookies`, `verify`/`verifyname`/CA bundle, `data_format`, the `https_ssl_verify` filter, and the `WP_HTTP_Proxy` → `WpOrg\Requests\Proxy\Http` with authentication
- [x] 4.3 Implement batching: `array_chunk` with keys preserved over the network-bound entries only, and `protected function send_batch(array $requests)` as the only caller of `\WpOrg\Requests\Requests::request_multiple()`, wrapped in `mbstring_binary_safe_encoding()`/`reset_mbstring_encoding()`
- [x] 4.4 Implement result conversion (D5): `WP_HTTP_Requests_Response` → `to_array()` + `http_response`, a Requests exception → `WP_Error('http_request_failed', $message)`, `http_api_debug`, the non-blocking empty response, and the `http_response` filter. Wrap every slot in `Response`, in the order the requests were added
- [x] 4.5 Write global classes fully qualified or imported. Use `\WpOrg\Requests\Requests::class` as the `http_api_debug` transport name

## 5. Pool tests (`tests/Unit/Http/Client/PoolTest.php`, using `RecordingPool`/`RecordingRequest`)

- [x] 5.1 Order and keys: `'a'`, unkeyed, `'b'`, unkeyed → `['a', 0, 'b', 1]`. `as(5)` then unkeyed → `6`. Each key holds its own response
- [x] 5.2 A duplicate `as()` key throws, and the seam is never called. A request registered with no verb call throws on send
- [x] 5.3 Failure isolation: a `WP_Error` from `pre_http_request`, a 500 result, and a Requests exception in the same batch each affect only their own entry (`failed()` true / `successful()` false), and the other entries are intact
- [x] 5.4 The `pre_http_request` callback receives the final URL (with the query string) and arguments (with the custom header). That request is absent from every recorded batch, and its response wraps the returned value. It gets no `http_api_debug` call and no `http_response` filter
- [x] 5.5 An `http_request_args` callback adds a header that shows up in every recorded batch payload
- [x] 5.6 A host blocked by the `WP_Http` stand-in, and an unsafe URL with `reject_unsafe_urls` true, each give a `WP_Error` for that request only. The other requests are sent
- [x] 5.7 Parity: for the same fluent chain (headers, timeout, JSON body, query), the `[$url, $args]` that `PooledRequest` records equals what the `wp_remote_request` stub received from a single request
- [x] 5.8 Concurrency: 25 requests at 10 → batches of 10/10/5 in add order. 12 requests with 2 short-circuited at 10 → one batch of 10. The default is 10. `0`, `-1` and `'10'` each throw before the callback runs
- [x] 5.9 An empty pool returns `[]` with no seam call
- [x] 5.10 Response shape: `http_api_debug` fires once for each sent request with that request's arguments and URL. The `http_response` filter's return value is what `Response` wraps. A non-blocking request gives the WordPress empty response. The SSL `verify` option is the CA bundle path, or the value from `https_ssl_verify`
- [x] 5.11 Run `composer phpcs && composer test:unit`. All tests pass, including every existing single-request test

## 6. Docs and release

- [x] 6.1 Create `docs/http-client.md`, structured like `docs/cache.md` (table of contents, numbered sections): intro; quick start (the S3 example); keys and ordering; errors and `Response` helpers; `concurrency` and batching; WordPress filters and actions that apply; arguments and behaviors that differ (no `https_local_ssl_verify`, only plural `reject_unsafe_urls`, batches wait for their slowest request, sequential fallback without cURL, verbs return the pooled request); scoping note (consumers must not prefix `WpOrg\Requests`); "Where this differs from Laravel" (no promises/async, no `Pool::response()`, duplicate keys throw, no parent inheritance)
- [x] 6.2 Create `CHANGELOG.md` (Keep a Changelog format) with a single `## [3.3.0]` entry: "Added" for `Http::pool()`, and "Fixed" for `Response::failed()` on `WP_Error`
- [x] 6.3 Bump `"version"` in `composer.json` from `3.2.8` to `3.3.0`

## 7. Verification outside the unit suite

- [x] 7.1 Run `make scope`. Grep the scoped `example/libraries/themeum/framework/Http/Client/Pool.php` and confirm that there are no `Themeum\WpOrg` or `Themeum\WP_` references, and that `\WpOrg\Requests\Requests` and the `WP_*` classes are still global
- [x] 7.2 In the Docker playground, start a `php -S` endpoint that sleeps 1 s for each request. Use `make wp CMD="eval-file …"` to pool 5 requests to it with concurrency 5, and confirm a total of about 1–2 s compared with about 5 s sequentially. Record both timings for the PR description
- [x] 7.3 In the same playground, confirm that a `pre_http_request` filter that short-circuits one URL stops that URL from being sent, while the others are sent
