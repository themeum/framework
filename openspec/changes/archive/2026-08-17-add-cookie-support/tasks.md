## 1. Cookie value object

- [x] 1.1 Create `src/Http/Cookie.php` with constructor `($name, $value = '', $expire = 0, $path = null, $domain = null, $secure = null, $http_only = true, $raw = false, $same_site = null)`, where `$expire` is a unix timestamp
- [x] 1.2 Add accessors in the repo's style: `get_name()`, `get_value()`, `get_expires_time()`, `get_path()`, `get_domain()`, `is_secure()`, `is_http_only()`, `is_raw()`, `get_same_site()`, `is_expired()`
- [x] 1.3 Normalize `same_site` to `Lax`/`Strict`/`None` regardless of input casing, and force `secure = true` when it is `None`
- [x] 1.4 Add `to_options()` returning the PHP 7.3+ `setcookie()` options array (`expires`, `path`, `domain`, `secure`, `httponly`, `samesite`)
- [x] 1.5 Add `to_header_string()` and `__toString()`, honoring the raw flag (no URL-encoding of the value when raw)
- [x] 1.6 Add the file docblock and per-member docblocks in the format the `Framework` PHPCS sniffs require (short description, `@since`, tag order)

## 2. CookieManager service

- [x] 2.1 Create `src/Managers/CookieManager.php` with `make($name, $value, $minutes = 0, $path = null, $domain = null, $secure = null, $http_only = true, $raw = false, $same_site = null)` converting `$minutes` to a timestamp, and `$minutes = 0` producing a session cookie (expiry `0`)
- [x] 2.2 Add `forever()` (same signature, ~5 years) and `forget($name, $path = null, $domain = null)` (empty value, past expiry, matching path/domain)
- [x] 2.3 Add protected `resolve_defaults()` reading `config('cookie.*')` first, then `COOKIEPATH`/`COOKIE_DOMAIN` guarded with `defined()` and `is_ssl()` guarded with `function_exists()`, then falling back to `/`, `null`, `false`, `http_only = true`, `same_site = 'Lax'`
- [x] 2.4 Add `set_default_path_and_domain($path, $domain, $secure = false, $same_site = null)` overriding the resolved defaults
- [x] 2.5 Add the queue: `queue(...$parameters)` accepting a `Cookie` or `make()` arguments, keyed by name then path so the same name on different paths coexists
- [x] 2.6 Add `unqueue($name, $path = null)`, `has_queued($name, $path = null)`, `queued($name, $default = null, $path = null)`, `get_queued_cookies()`
- [x] 2.7 Add `flush_queued_cookies()` that writes every queued cookie then clears the queue, so a second flush emits nothing
- [x] 2.8 Add protected `write(Cookie $cookie)` — the single `setcookie()` call — guarded by `headers_sent()`; on the guarded path log a warning naming the cookie through `LogManager::warning()` and return without throwing

## 3. Container wiring, facade, and helper

- [x] 3.1 Register `CookieManager` as a singleton in `CoreServiceProvider::register_managers()`
- [x] 3.2 Add the `'cookie' => CookieManager::class` alias in `Application::register_base_aliases()`
- [x] 3.3 Create `src/Supports/Facades/Cookie.php` extending `Framework\Facade` with `get_accessor()` returning `'cookie'`, modeled on `src/Supports/Facades/Option.php`, including `@method static` docblock lines for the full manager API
- [x] 3.4 Add `Framework\cookie()` to `src/helpers.php` guarded by `function_exists`: no arguments returns `app('cookie')`, arguments return `app('cookie')->make(...)` without sending

## 4. Request cookie bag

- [x] 4.1 Add a `protected array $cookies = []` property to `src/Http/Request.php`
- [x] 4.2 Add `cookie($key = null, $default = null)`, `cookies()`, and `has_cookie($key)` reading only from that bag
- [x] 4.3 Add a trailing `$cookies = []` parameter to `make_from_http()` (after `$route_params`, keeping existing calls valid) and populate the bag through the existing `unslash_array()`
- [x] 4.4 Populate the bag in `make_request(WP_REST_Request $request)` from `$_COOKIE`, since `WP_REST_Request` carries no cookie params
- [x] 4.5 Pass `$_COOKIE` from `Request::capture()` and from `Route::dispatch_site()`, using the same `phpcs:ignore Framework.NamingConventions.SnakeCaseVariable` comment style already applied to the other superglobals
- [x] 4.6 Verify cookies never reach `$attributes`: absent from `all()`, from `get()` and the typed accessors, from the `__get()` magic accessor, and from validation/sanitization input

## 5. Response attachment and flush points

- [x] 5.1 Create `src/Http/Concerns/InteractsWithCookies.php` with `with_cookie()`, `with_cookies()`, `without_cookie()` delegating into the `CookieManager` queue and returning `$this`, following the `Concerns/InteractsWithFiles` pattern
- [x] 5.2 Apply the trait to `src/Http/Response.php`, `src/Http/JsonResponse.php`, and `src/Http/RedirectResponse.php`
- [x] 5.3 Flush queued cookies in `RedirectResponse::send()` before `wp_safe_redirect()`, since that call is followed by `exit`
- [x] 5.4 Flush at the top of `SiteRouter::send_response()` before any `header()` or `echo`
- [x] 5.5 Flush in `SiteRouter::dispatch_match()` before `send_route_redirect()` and before the `include $file` template branch
- [x] 5.6 Add `SEND_HEADERS = 'send_headers'` and `REST_POST_DISPATCH = 'rest_post_dispatch'` to `src/Wordpress/Constants/HookNames.php`
- [x] 5.7 Create `src/Wordpress/Hooks/Actions/FlushQueuedCookies.php` (a `BaseHook` on `send_headers`) whose `handle()` flushes the queue
- [x] 5.8 Create `src/Wordpress/Hooks/Filters/FlushRestCookies.php` (new `Hooks/Filters/` directory) on `rest_post_dispatch`, flushing the queue and **returning `$args[0]` unchanged** so REST responses pass through untouched
- [x] 5.9 Register both hooks in `HookServiceProvider::$defaults` under `actions` and `filters` respectively

## 6. Outbound HTTP client cookies

- [x] 6.1 Add `with_cookie($name, $value, $domain = null)` and `with_cookies(array $cookies, $domain = null)` to `src/Http/Client/Request.php`
- [x] 6.2 Normalize all three accepted shapes — name/value pairs, `Framework\Http\Cookie` objects, and `WP_Http_Cookie` instances — into `WP_Http_Cookie` entries in `$this->options['cookies']`
- [x] 6.3 Read through `with_options()`, `prepare_request_args()`, and `send_request()` to confirm `'cookies'` actually survives the merge into `wp_remote_request()` args; fix the merge if it does not

## 7. Tests

- [x] 7.1 Add WordPress stubs to `tests/Support/StubsWordPressFunctions.php`: `is_ssl()`, a controllable `headers_sent()`, and a `WP_Http_Cookie` class stub
- [x] 7.2 Add a `CookieManager` test double overriding the protected `write()` to record emitted cookies instead of calling `setcookie()`
- [x] 7.3 Write `tests/Unit/Http/CookieTest.php`: header string generation, same-site normalization, `None` forcing `secure`, session cookie for zero lifetime, expiry math, raw vs URL-encoded values
- [x] 7.4 Write `tests/Unit/Managers/CookieManagerTest.php`: `make`/`forever`/`forget` expiries, queue/unqueue/`has_queued`/`queued`, same name on different paths, flush emitting then clearing, defaults resolution across config/constants/per-cookie arguments, and the `headers_sent()` path skipping the write and logging
- [x] 7.5 Write `tests/Unit/Http/RequestCookieTest.php`: reads via both `make_from_http()` and `make_request()`, unslashing, default values, and assertions that cookies are absent from `all()`, `get()`, and magic property access
- [x] 7.6 Write `tests/Unit/Http/ClientCookieTest.php` covering normalization of all three input shapes into the `cookies` option
- [x] 7.7 Extend `tests/Unit/TestCase.php` teardown if any new global or container state needs resetting between tests

## 8. Documentation and verification

- [x] 8.1 Write `docs/cookies.md` in the style of `docs/routing-examples.md`: facade, `cookie()` helper, request reads, response attachment, outbound client cookies, defaults and configuration, and an explicit warning that values are unencrypted and must be validated
- [x] 8.2 Run `composer test:unit` and fix any regressions
- [x] 8.3 Run `composer phpcs` and clear all violations in the new and modified files
- [ ] 8.4 Manually verify the round trip in the example app across all three paths: a site route returning `response()->json(...)->with_cookie(...)` then reading it back, a REST route doing the same (exercising `rest_post_dispatch`), a `redirect()` after `Cookie::queue(...)` landing the cookie despite the `exit`, and `Cookie::forget()` clearing it in the browser
