# HTTP client

The `Http` facade is a fluent client on top of `wp_remote_request()`. This guide covers `Http::pool()`, which sends many requests at the same time. The API follows Laravel's `Http::pool()`, adapted to the framework's snake_case method naming and to the WordPress HTTP API. `Http::pool()` is available from framework 3.3.0.

## Table of contents

1. [Quick start](#1-quick-start)
2. [Building pooled requests](#2-building-pooled-requests)
3. [Keys and ordering](#3-keys-and-ordering)
4. [Errors](#4-errors)
5. [Concurrency](#5-concurrency)
6. [WordPress filters and actions](#6-wordpress-filters-and-actions)
7. [Behaviour worth knowing](#7-behaviour-worth-knowing)
8. [Scoping](#8-scoping)
9. [Where this differs from Laravel](#9-where-this-differs-from-laravel)

---

## 1. Quick start

Sending requests one after another adds up their times: 22 image downloads of about 2 s each take about 45 s. A pool sends them together, so the total time is close to the time of the slowest request.

```php
use Framework\Http\Client\Pool;
use Framework\Supports\Facades\Http;

$responses = Http::pool(function (Pool $pool) use ($urls) {
    foreach ($urls as $key => $url) {
        $pool->as($key)->timeout(15)->get($url);
    }
}, ['concurrency' => 10]);

$responses['canvas-tote-bag-1.jpg']->successful();
$responses['canvas-tote-bag-1.jpg']->body();
```

`pool()` returns an array of `Framework\Http\Client\Response`, one for each pooled request, with the same API as the response of a single request. Inside a scoped plugin, `Framework\` becomes your prefixed namespace, for example `Themeum\Framework\Http\Client\Pool`.

---

## 2. Building pooled requests

Each call on the pool starts a new request:

```php
$pool->as('avatar')->with_token($token)->get($url);   // keyed
$pool->post($endpoint, ['sku' => 'tote-1']);          // unkeyed
```

Every fluent method of a single request works in the same way on a pooled request: `with_headers`, `timeout`, `with_token`, `with_user_agent`, `with_cookies`, `with_body`, `as_json`, `as_form`, `as_multipart`, `attach`, `with_options`, `without_verifying`, `accept_json`, `base_url`, and macros. The final URL and arguments are built by the same code as for a single request.

Inside a pool, the verb methods (`get`, `post`, `put`, `patch`, `delete`, `head`, `options`, `send`) **record** the request and return the pooled request. They do not send it, and they do not return a `Response`. The pool sends everything after the callback returns. The callback's return value is ignored, so you don't need to collect the requests yourself.

Pooled requests start with the defaults of a new request. They do **not** inherit the settings of the request that `pool()` was called on. In `Http::with_token($t)->pool(...)`, the token does not reach the pooled requests. Put shared settings inside the callback, for example with a local closure:

```php
Http::pool(function (Pool $pool) use ($urls, $token) {
    $authed = function ($key) use ($pool, $token) {
        return $pool->as($key)->with_token($token)->timeout(15);
    };

    foreach ($urls as $key => $url) {
        $authed($key)->get($url);
    }
});
```

---

## 3. Keys and ordering

- `as($key)` stores the request's response under `$key`, which is a string or an integer.
- A request without a key gets the next integer index under PHP's array-append rules: `0`, `1`, … or, after `as(5)`, `6`.
- The result keeps the order in which the requests were added, whatever order they finish in.
- Using a key twice in one pool throws `InvalidArgumentException` from `as()`, before anything is sent. This includes `as(0)` after an unkeyed request already took `0`. As in PHP, `'5'` and `5` are the same key.
- A pooled request that never gets a verb method (`$pool->as('x')->timeout(5);`) throws `RuntimeException` when the callback returns, before anything is sent.
- An empty pool returns `[]` and sends nothing.

---

## 4. Errors

A failed request never throws and never affects the other requests in the pool. Its entry is a `Response` that wraps a `WP_Error`, the same as for a failed single request:

| Cause | `WP_Error` code |
|---|---|
| Transport failure (timeout, DNS, TLS, …) | `http_request_failed`, with the transport's message |
| URL without a valid scheme, or rejected by `reject_unsafe_urls` | `http_request_failed` |
| Host blocked by `WP_HTTP_BLOCK_EXTERNAL` | `http_request_not_executed` |
| Stream destination not writable | `http_request_failed` |
| `pre_http_request` returned a `WP_Error` | whatever the filter returned |

For these entries, `successful()` is `false`, `failed()` is `true`, `on_error()` runs its callback, and `status()` is `0`. An HTTP error status, such as a 500, is a normal response with that status.

Programmer errors still throw: an invalid HTTP method or body format (when the verb is called), a duplicate key, a request with no verb, and an invalid `concurrency`.

> Since 3.3.0, `Response::failed()` also returns `true` for a `WP_Error` on a **single** request. Before 3.3.0 it returned `false`, because the status read as `0`.

---

## 5. Concurrency

`concurrency` (default `10`) is the maximum number of requests in flight at the same time. It must be a positive integer. Any other value (`0`, `-1`, `'10'`) throws `InvalidArgumentException` before the callback runs.

A larger pool is sent in consecutive batches of `concurrency` requests, in the order the requests were added. Each batch must finish before the next one starts, so a batch takes as long as its slowest request. For 25 requests at concurrency 10, the pool sends three batches: 10, 10 and 5.

Requests that are answered before the network (by a `pre_http_request` short-circuit, or by a blocked or unsafe URL) do not take a place in a batch.

The requests are sent with `WpOrg\Requests\Requests::request_multiple()`, the parallel transport that WordPress bundles. With cURL they run in parallel. With only the fsockopen transport, Requests sends them one after another. The results are still correct, but you get no speed-up.

---

## 6. WordPress filters and actions

`request_multiple()` bypasses `WP_Http::request()`, so the pool repeats what `WP_Http::request()` (WordPress 6.9.4) does for each request. These run for every pooled request, in the order WordPress runs them:

| Hook | Arguments | Notes |
|---|---|---|
| `http_request_timeout`, `http_request_redirection_count`, `http_request_version`, `http_headers_useragent`, `http_request_reject_unsafe_urls` | default, `$url` | Defaults only. The values set on the request win, as with `wp_remote_request()`. |
| `http_request_args` | `$args, $url` | |
| `pre_http_request` | `false, $args, $url` | A non-`false` result becomes that request's response, and that request is not sent. As in WordPress, a short-circuited request gets no `http_api_debug` and no `http_response`. |
| `https_ssl_verify` | `$verify, $url` | `$verify` is the WordPress CA bundle, or `false` after `without_verifying()`. |
| `requests-requests.before_redirect` and the other `requests-*` hooks | as WordPress | Through `WP_HTTP_Requests_Hooks`, with the same redirect compatibility and redirect validation as in WordPress. |
| `http_api_debug` (action) | `$response, 'response', 'WpOrg\Requests\Requests', $args, $url` | Fires for every sent or rejected request. |
| `http_response` | `$response, $args, $url` | Runs on every successful blocking response. |

URL checks match WordPress too. With `reject_unsafe_urls` set, `wp_http_validate_url()` checks the URL, and redirects are validated. `WP_Http::block_request()` applies `WP_HTTP_BLOCK_EXTERNAL` and `WP_ACCESSIBLE_HOSTS`. The pool also honours proxy settings (`WP_HTTP_Proxy`), cookies, `stream` with `filename`, `limit_response_size` and non-blocking requests.

Since the hooks are the same, tests that stub HTTP with `pre_http_request` also stub pooled requests.

---

## 7. Behaviour worth knowing

**`https_local_ssl_verify` does not apply.** WordPress applies it only in the legacy Curl and Streams transports and in the cron loopback, never on the `wp_remote_request()` path. The pool follows `wp_remote_request()`, so a request verifies SSL in the same way whether it is pooled or sent alone.

**Use `reject_unsafe_urls` (plural).** WordPress reads `reject_unsafe_urls`. The client's own default, `reject_unsafe_url` (singular), has never had an effect on single requests, and it has none on pooled requests either. Turn the check on with `with_options(['reject_unsafe_urls' => true])` or with the `http_request_reject_unsafe_urls` filter.

**HEAD requests follow redirects.** In WordPress, HEAD requests follow no redirects by default. The client always sets `redirection` to 5 itself, so its HEAD requests follow up to 5 redirects, on single and pooled requests alike. Set `with_options(['redirection' => 0])` to turn this off.

**Batches, not a sliding window.** See [Concurrency](#5-concurrency).

---

## 8. Scoping

The pool uses global WordPress symbols: `WpOrg\Requests\*`, `WP_Http`, `WP_HTTP_Proxy`, `WP_HTTP_Requests_Response`, `WP_HTTP_Requests_Hooks` and `WP_Error`. Your php-scoper config must leave them unprefixed. The recommended config excludes every namespace except `Framework`, which covers all of them:

```php
'exclude-namespaces' => [
    '~^$~',
    '/^(?!Framework($|\\\\))/',
],
'expose-global-classes' => true,
```

If your config lists the namespaces to exclude one by one, add `WpOrg\Requests`.

---

## 9. Where this differs from Laravel

**No promises, no async.** Laravel's pool runs on Guzzle promises. Here, `pool()` blocks until every request has finished and returns plain `Response` objects. Pooled verbs return the pooled request, not a promise.

**Batches instead of a rolling window.** Laravel's `concurrency` keeps N requests in flight at all times. WordPress's `request_multiple()` has no such option, so this pool sends fixed batches, and each batch waits for its slowest request.

**Duplicate keys throw.** In Laravel, a later request with the same key silently replaces the earlier response. Here, `as()` throws, so no response is ever lost.

**No inheritance from the parent request.** This matches Laravel, where pooled requests are also fresh. It is stated here because `Http::with_token()->pool()` looks as if it should share the token.

**Failures are `WP_Error`, not exceptions.** Laravel can return a `ConnectionException` object for a failed request. Here, a failed request is a `Response` that wraps a `WP_Error`, as for a single `Http::` call.

**No retries.** `retry()` is not available for single requests either.
