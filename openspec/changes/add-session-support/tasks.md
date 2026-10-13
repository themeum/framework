## 1. Driver contract and handlers

- [x] 1.1 Create `src/Contracts/SessionHandler.php` with `read(string $id)` returning an array, `write(string $id, array $payload, int $lifetime)` returning bool, and `destroy(string $id)` returning bool, following the docblock format the `Framework` PHPCS sniffs require
- [x] 1.2 Create `src/Session/Handlers/TransientSessionHandler.php` (new `src/Session/` directory) using `get_transient` / `set_transient` / `delete_transient`, with a protected `storage_key(string $id)` returning `with_prefix('session_' . $id)` via the existing `Framework\with_prefix()` helper
- [x] 1.3 Make `read()` return an empty array when the transient is missing or is not an array, so a corrupted or expired payload degrades to a fresh session rather than an error
- [x] 1.4 Create `src/Session/Handlers/ArraySessionHandler.php` holding payloads in a protected property for the current request only, persisting nothing
- [x] 1.5 Add a protected `is_external_object_cache()` seam to the transient handler wrapping `wp_using_ext_object_cache()` guarded with `function_exists()`, used for the boot notice in task 4.4

## 2. SessionManager

- [x] 2.1 Create `src/Managers/SessionManager.php` with a constructor taking a `SessionHandler`, and protected state for `$id`, `$attributes`, `$started`, `$modified`, and `$id_from_request`
- [x] 2.2 Add identifier handling: `generate_id()` using `bin2hex(random_bytes(20))` with a `wp_generate_password(40, false, false)` fallback inside a try/catch, and `is_valid_id(string $id)` matching `/^[a-f0-9]{40}$/`
- [x] 2.3 Add `start()` — read the identifier from the request cookie bag, validate it, load the payload through the handler when valid (recording `$id_from_request = true`), otherwise generate a fresh identifier; then queue the identifier cookie through `CookieManager` and mark the session started. Starting twice must be a no-op
- [x] 2.4 Add lazy-read behaviour: `get()`, `all()`, `has()`, `exists()`, `only()` must return defaults **without** starting a session when no valid request cookie is present, and must load the payload when one is
- [x] 2.5 Add the write verbs, each forcing a start and setting `$modified`: `put()` (single key or array), `push()`, `increment()`, `decrement()`, `remember()`, `pull()`, `forget()` (one key or many), `flush()`
- [x] 2.6 Route all key access through `Framework\Supports\Arr::get()` / `Arr::set()` / `Arr::forget()` so dot notation works, matching the rest of the framework
- [x] 2.7 Add the flash verbs: `flash()`, `now()`, `reflash()`, `keep()`, `flash_input()`, `get_old_input()`, storing bookkeeping under `_flash.old` and `_flash.new` exactly as Laravel does
- [x] 2.8 Add `age_flash_data()` — called from `save()` — that forgets the keys in `_flash.old`, moves `_flash.new` into `_flash.old`, and empties `_flash.new`
- [x] 2.9 Add identity operations: `regenerate($destroy = false)`, `invalidate()`, `migrate($destroy = false)`, each regenerating `_token` and re-queueing the identifier cookie; all three force a start
- [x] 2.10 Add `token()`, `regenerate_token()`, `get_id()`, `get_name()`, `is_started()`, `previous_url()`, `set_previous_url()`
- [x] 2.11 Add `save()` — return early when not started or not modified; call `age_flash_data()`; apply the late-write rule (persist when `$id_from_request` is true, otherwise skip and log a warning through `LogManager::warning()` when `headers_sent()`); write through the handler with the configured lifetime; clear `$modified`
- [x] 2.11a Add protected `holds_application_data()` returning false when the payload contains only `_token`, empty `_flash.old` / `_flash.new`, and `_previous`; in `save()`, when it returns false, destroy any stored payload through the handler, expire the identifier cookie via `CookieManager::expire()`, and write nothing
- [x] 2.12 Add protected `resolve_defaults()` reading `config('session.*')` (driver, lifetime, expire_on_close, cookie, path, domain, secure, http_only, same_site) with framework fallbacks, lazily cached in a `$defaults` property exactly as `CookieManager::get_defaults()` does
- [x] 2.13 Add protected seams `headers_already_sent()` and `log_warning()` mirroring `CookieManager`, so tests can override them without touching global state
- [x] 2.14 Queue the identifier cookie through `CookieManager::queue()` — never a direct `setcookie()` call — with `http_only = true`, `same_site` from defaults (`Lax`), `secure` from `is_ssl()`, and a zero lifetime when `expire_on_close` is configured

## 3. Error bag

- [x] 3.1 Create `src/Supports/ErrorBag.php` wrapping a plain `['field' => ['message', ...]]` array with `has()`, `first()`, `get()`, `all()`, `any()`, `count()`
- [x] 3.2 Keep it read-only and constructed at read time — no `ErrorBag` instance may ever be written into the session payload

## 4. Container wiring, facade, helpers, and config

- [x] 4.1 Bind `SessionHandler` in `CoreServiceProvider::register_managers()` to the handler named by `config('session.driver', 'database')`, mapping `database` to the transient handler and `array` to the array handler, and throwing on an unknown driver name rather than falling back
- [x] 4.2 Register `SessionManager` as a singleton in the same method
- [x] 4.3 Add `'session' => SessionManager::class` to `Application::register_base_aliases()`
- [x] 4.4 Log a notice at boot when the driver is `database` and an external object cache is active, stating that session durability depends on that cache
- [x] 4.5 Create `src/Supports/Facades/Session.php` extending `Framework\Facade` with `get_accessor()` returning `'session'`, modelled on `src/Supports/Facades/Cookie.php`, with `@method static` lines covering the full manager API
- [x] 4.6 Add `Framework\session()` to `src/helpers.php` guarded by `function_exists`: no arguments returns `app('session')`, a string key reads with a default, an array writes pairs and returns null
- [x] 4.7 Add `Framework\old($key = null, $default = null)` reading flashed old input, and `Framework\errors()` returning an `ErrorBag` built from the flashed errors array
- [x] 4.8 Add `Framework\back($fallback = null, $status = 302)` resolving `wp_get_referer()` → `previous_url()` when a session is already started → `$fallback` → `home_url()`, returning a `RedirectResponse`; it must not start a session
- [x] 4.9 Confirm no existing helper in `src/helpers.php` already claims the names `session`, `old`, `errors`, or `back` before adding them
- [x] 4.10 Create `example/config/session.php` documenting every key with the framework defaults as values

## 5. Request integration

- [x] 5.1 Add `session()` to `src/Http/Request.php` returning the `SessionManager` from the container
- [x] 5.2 Add `old($key = null, $default = null)` delegating to the manager's old-input accessor
- [x] 5.3 Add `flash()`, `flash_only(array $keys)`, and `flash_except(array $keys)` flashing the request's input as old input
- [x] 5.4 Verify session state never reaches `$attributes`: absent from `all()`, from `get()` and the typed accessors, from `__get()`, and from validation and sanitization input — the same isolation documented for the cookie bag at `src/Http/Request.php:74`

## 6. Response and routing integration

- [x] 6.1 Create `src/Http/Concerns/InteractsWithFlashData.php` with `with($key, $value = null)`, `with_input(array $input = null)`, and `with_errors($errors)`, each returning `$this`, following the `InteractsWithCookies` pattern including a protected `session_manager()` accessor
- [x] 6.1a In `with_input()`, strip sensitive fields before flashing via the existing `Request::except()` at `src/Http/Request.php:925`, defaulting to `['password', 'password_confirmation', 'current_password']` merged with `config('session.dont_flash', [])`, and strip uploaded files so no file object reaches the storage driver
- [x] 6.2 Apply the trait to `src/Http/RedirectResponse.php`
- [x] 6.3 In `RedirectResponse::send()`, save the session **before** the existing `flush_queued_cookies()` call, so a newly queued identifier cookie is emitted in the same pass, and both happen before `wp_safe_redirect()`
- [x] 6.4 In `src/Routing/SiteRouter.php`, save the session immediately before each existing `flush_queued_cookies()` call — `send_response()` and both `dispatch_match()` branches — preserving that ordering
- [x] 6.5 Add `WP_LOGOUT = 'wp_logout'` and `SHUTDOWN = 'shutdown'` to `src/Wordpress/Constants/HookNames.php`
- [x] 6.6 Create `src/Wordpress/Hooks/Actions/SaveSession.php` on `shutdown`, `MigrateSessionOnLogin.php` on `wp_login` calling `migrate(false)`, and `InvalidateSessionOnLogout.php` on `wp_logout` calling `invalidate()`, each a `BaseHook` subclass shaped like `FlushQueuedCookies.php`
- [x] 6.7 Make all three hooks no-op when no session was started, so lazy behaviour holds for sites that never use sessions
- [x] 6.8 Register the three hooks in `HookServiceProvider::$defaults` under `actions`
- [x] 6.9 Save the session in the existing `rest_post_dispatch` filter hook alongside the cookie flush, returning the response argument unchanged

## 7. Tests

- [x] 7.1 Add WordPress stubs to `tests/Support/StubsWordPressFunctions.php`: `get_transient`, `set_transient`, `delete_transient` backed by an inspectable in-memory store, plus `wp_get_referer`, `wp_using_ext_object_cache`, and `wp_generate_password`
- [x] 7.2 Add a recording session handler double under `tests/Support/Session/`, mirroring `tests/Support/Http/RecordingCookieManager.php`
- [x] 7.3 Add a `SessionManager` test double overriding `headers_already_sent()` and `log_warning()` so the late-write rule is testable without global state
- [x] 7.4 Write `tests/Unit/Managers/SessionManagerTest.php` covering: lazy start (a read-only request queues no cookie and issues no write), first write starting the session and queueing exactly one cookie, write/read round trip including dot notation, `pull`, `has` vs `exists` with a null value, and `forget`/`flush`
- [x] 7.5 Cover flash ageing across three simulated requests, `now()` not surviving a save, `reflash()` extending everything, and `keep()` extending only the named keys
- [x] 7.6 Cover `regenerate` (identifier changes, data survives), `invalidate` (identifier changes, data gone), and `migrate` with and without destroying the previous payload — asserting `_token` rotates in each
- [x] 7.7 Cover identifier validation: a malformed cookie value is discarded, no storage read is issued with it, and a fresh identifier is generated
- [x] 7.8 Cover the late-write rule in both directions — persisted when the identifier came from the request, skipped with a logged warning when it was generated this request
- [x] 7.9a Cover pruning: a session whose flash data has aged out and holds only bookkeeping destroys its stored payload and expires its cookie instead of writing; a session holding one application value is persisted normally; a session holding only `_token` is pruned
- [x] 7.9 Cover save idempotency: no write when never started, and no write when started but unmodified
- [x] 7.10 Write `tests/Unit/Session/TransientSessionHandlerTest.php` covering the round trip, the configured lifetime being passed as the transient expiry, a missing or non-array transient yielding an empty array, key prefixing, and the key staying under WordPress's 172-character transient-name limit
- [x] 7.11 Cover the array driver persisting nothing across handler instances
- [x] 7.11a Assert that flashing input containing `password`, `password_confirmation`, `current_password`, or an uploaded file writes none of them to the storage driver — inspect the recorded payload, not just the reader API
- [x] 7.12 Assert the save-before-flush ordering at each `SiteRouter` dispatch branch and in `RedirectResponse::send()`, so a future reordering that would drop the identifier cookie fails the suite
- [x] 7.13 Assert session state is absent from `Request::all()`, `__get()`, and validation input

## 8. Documentation

- [x] 8.1 Write `docs/sessions.md` structured like `docs/cookies.md`, opening with the limits: no encryption, no locking (last-write-wins), per-site on multisite, cache-durable rather than guaranteed-durable under an object cache, and WordPress nonces — not the parity token — as the CSRF defence
- [x] 8.2 Document the lazy-start model explicitly, including why it differs from Laravel and that `get_id()` is null until a start is forced
- [x] 8.3 Document reading and writing, flash data, `old()` and `errors()` in views, and the `back()` resolution order
- [x] 8.3a Document that validation failures are **not** flashed automatically — site routes still end in a 422 error page and REST routes in 422 JSON — and show the opt-in pattern `back()->with_errors($e->get_errors())->with_input()` for controllers that want post-redirect-get
- [x] 8.4 Document configuration keys and driver selection, and state that no driver substitution ever happens silently
- [x] 8.5 Document that flashed old input excludes password fields and uploaded files by default, that `session.dont_flash` extends that list, and that nothing flashed is encrypted
- [x] 8.6 Document that a session holding only bookkeeping is pruned on save — its payload destroyed and its cookie expired — so `token()` alone does not keep a session alive
- [x] 8.7 Document that the array driver cannot support redirect-back: the redirect succeeds but errors and old input are gone, with no failure reported

## 9. Verification

- [x] 9.1 Run `composer test` and confirm the suite passes
- [x] 9.2 Run `composer phpcs` and confirm the `Framework` sniffs pass on every new and modified file
- [ ] 9.3 In the Docker WordPress, load a front-end route that never touches the session and confirm no `Set-Cookie` header and no new `_transient_*` rows in `wp_options`
- [ ] 9.4 Hit a route that writes a session value; confirm exactly one session cookie and one transient pair appear, and that the value reads back on reload
- [ ] 9.5 In a controller, call `back()->with_errors([...])->with_input()` explicitly; confirm the redirect, `errors()->first('field')` rendering, and `old('field')` repopulating the input
- [ ] 9.6 Log in and confirm the identifier changes while data survives; log out and confirm the data is gone
- [x] 9.7 Switch `config('session.driver')` to `array` and confirm nothing persists across requests
