## Why

The framework has no cookie handling at all — a controller cannot set a cookie, `$request` cannot read one, and the outbound HTTP client cannot send one (its `'cookies'` option is declared but never populated). Applications built on the framework must fall back to raw `$_COOKIE` and `setcookie()`, bypassing the framework's request lifecycle entirely.

## What Changes

- Add a `Framework\Http\Cookie` value object describing a single cookie (name, value, expiry, path, domain, secure, http_only, same_site, raw)
- Add `Framework\Managers\CookieManager` — a Laravel `CookieJar`-equivalent factory and queue with `make`, `forever`, `forget`, `queue`, `unqueue`, `has_queued`, `queued`, `get_queued_cookies`, `flush_queued_cookies`, `set_default_path_and_domain`
- Add a `Cookie` facade (`Framework\Supports\Facades\Cookie`) and a global `Framework\cookie()` helper following Laravel's signature
- Add cookie reads to `Framework\Http\Request`: `cookie()`, `cookies()`, `has_cookie()`, backed by a **separate bag** that never enters `$attributes`, `all()`, or validation input
- Add `with_cookie()` / `with_cookies()` / `without_cookie()` to `Response`, `JsonResponse`, and `RedirectResponse` via a shared concern; these delegate into the manager's queue rather than storing cookies per-response
- Flush the queue at every response send point — `SiteRouter` dispatch branches, `RedirectResponse::send()`, the `rest_post_dispatch` filter for REST responses WordPress serializes itself, and the `send_headers` action for ordinary page loads
- Add `with_cookie()` / `with_cookies()` to the outbound HTTP client, normalizing name/value pairs, `Cookie` objects, and `WP_Http_Cookie` instances into the existing `'cookies'` request option
- Cookie values are **not encrypted or signed**. Unlike Laravel, there is no encrypter in this framework; cookies are stored plainly as WordPress core does and documented as untrusted client input

## Capabilities

### New Capabilities

- `http-cookies`: Creating, queueing, and emitting cookies; reading request cookies as a bag isolated from input; attaching cookies to responses; and sending cookies on outbound HTTP client calls

### Modified Capabilities

<!-- none -->

## Impact

- `src/Http/Cookie.php` — new value object
- `src/Managers/CookieManager.php` — new factory and queue service
- `src/Supports/Facades/Cookie.php` — new facade
- `src/Http/Concerns/InteractsWithCookies.php` — new response concern
- `src/Wordpress/Hooks/Actions/FlushQueuedCookies.php` and `src/Wordpress/Hooks/Filters/FlushRestCookies.php` — new flush hooks (`Hooks/Filters/` is a new directory)
- `src/Http/Request.php` — cookie bag plus a new trailing `$cookies` parameter on `make_from_http()`
- `src/Http/Response.php`, `src/Http/JsonResponse.php`, `src/Http/RedirectResponse.php` — response cookie API; `RedirectResponse::send()` flushes before `wp_safe_redirect()`
- `src/Routing/SiteRouter.php` — flush before output on every dispatch branch
- `src/Route.php` — `dispatch_site()` passes `$_COOKIE` through
- `src/Http/Client/Request.php` — outbound cookie methods
- `src/CoreServiceProvider.php`, `src/Application.php` — singleton registration and the `'cookie'` alias
- `src/helpers.php` — `Framework\cookie()`
- `src/Wordpress/Constants/HookNames.php`, `src/Wordpress/HookServiceProvider.php` — new hook constants and default hook registration
- `tests/Unit/Http/`, `tests/Unit/Managers/`, `tests/Support/StubsWordPressFunctions.php` — unit coverage and WordPress stubs (`is_ssl`, `headers_sent`, `WP_Http_Cookie`)
- `docs/cookies.md` — new documentation page
- No new dependencies; no breaking changes to existing signatures
