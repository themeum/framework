## Why

The framework has request, cookie, routing, validation, and view layers but no session layer, so nothing can carry state between two requests. There is no flash data and therefore no post-redirect-get story: a site route that fails validation today ends the request with a `wp_die()` 422 instead of redirecting back with errors and the submitted input. Applications are left to hand-roll transients or reach for raw `$_SESSION`, which WordPress does not manage and many managed hosts disable outright.

## What Changes

- Add `Framework\Managers\SessionManager` — a single flat service carrying Laravel's `Store` verb set (`start`, `get`, `put`, `has`, `exists`, `pull`, `push`, `increment`, `decrement`, `remember`, `all`, `only`, `forget`, `flush`, `flash`, `now`, `reflash`, `keep`, `flash_input`, `get_old_input`, `regenerate`, `invalidate`, `migrate`, `save`, `token`, `regenerate_token`), modelled structurally on `CookieManager`
- Add a `Framework\Contracts\SessionHandler` contract with two implementations: `TransientSessionHandler` (WordPress transients — no new table, no garbage-collection cron) and `ArraySessionHandler` (in-memory, no persistence), selected by `config('session.driver')`
- Sessions start **lazily**: no session cookie and no stored payload exist until something is written. Reads on a session-less request return defaults silently. This deviates from Laravel, which starts eagerly on every `web` request, because a `Set-Cookie` on every anonymous page view disables full-page caching sitewide in WordPress
- Add a `Session` facade (`Framework\Supports\Facades\Session`) and a global `Framework\session()` helper with Laravel's three-way signature
- Add flash data with Laravel-exact `_flash.old` / `_flash.new` aging performed inside `save()`
- Add `with()`, `with_input()`, and `with_errors()` to `RedirectResponse` through a new `InteractsWithFlashData` concern, alongside the existing `InteractsWithCookies`
- Add `session()`, `old()`, `flash()`, `flash_only()`, and `flash_except()` to `Framework\Http\Request`; session data is held in a bag isolated from `$attributes`, `all()`, and validation input, exactly as the cookie bag already is
- Add `Framework\old()`, `Framework\errors()`, and `Framework\back()` helpers, plus a read-only `Framework\Supports\ErrorBag`
- Validation failure handling is **unchanged**: site routes keep today's `wp_die()` 422 error page and REST routes keep their 422 JSON. Errors are never flashed automatically; a controller that wants redirect-back calls `back()->with_errors(...)->with_input()` itself
- Register three default hooks: `shutdown` (save safety net), `wp_login` (`migrate` — session-fixation defence, data preserved), and `wp_logout` (`invalidate`)
- Flashed old input excludes `password`, `password_confirmation`, `current_password`, and uploaded files by default, extendable via `config('session.dont_flash')` — the framework has no encrypter, so a flashed password would be stored in plaintext
- Session payloads are **not encrypted**, are stored per-site on multisite, and carry no locking — concurrent read-modify-write is last-write-wins. Under an external object cache, transients live in the cache, so sessions are cache-durable rather than guaranteed-durable. All three are documented limits, not defects
- A `_token` value is provided for Laravel parity, but **no CSRF middleware is added** — WordPress nonces remain the framework's CSRF defence

## Capabilities

### New Capabilities

- `http-sessions`: Starting, reading, writing, flashing, and persisting session state across requests; the session-ID cookie lifecycle; the transient and array drivers; request and redirect-response integration;

### Modified Capabilities

<!-- none -->

## Impact

- `src/Contracts/SessionHandler.php` — new driver contract
- `src/Session/Handlers/TransientSessionHandler.php`, `src/Session/Handlers/ArraySessionHandler.php` — new drivers (`src/Session/` is a new directory)
- `src/Managers/SessionManager.php` — new service
- `src/Supports/Facades/Session.php` — new facade
- `src/Supports/ErrorBag.php` — new read-only error wrapper
- `src/Http/Concerns/InteractsWithFlashData.php` — new response concern
- `src/Wordpress/Hooks/Actions/SaveSession.php`, `MigrateSessionOnLogin.php`, `InvalidateSessionOnLogout.php` — new default hooks
- `src/Http/Request.php` — session bag and old-input accessors
- `src/Http/RedirectResponse.php` — flash-data concern; `send()` saves the session before `wp_safe_redirect()`
- `src/Routing/SiteRouter.php` — save the session at each existing cookie-flush point, ordered before the flush so a newly queued ID cookie is emitted in the same pass
- `src/CoreServiceProvider.php`, `src/Application.php` — singleton registration, driver binding, and the `'session'` alias
- `src/helpers.php` — `session()`, `old()`, `errors()`, `back()`
- `src/Wordpress/Constants/HookNames.php`, `src/Wordpress/HookServiceProvider.php` — `WP_LOGOUT` and `SHUTDOWN` constants; default hook registration
- `example/config/session.php` — sample configuration; every key optional, with the manager resolving defaults as `CookieManager` does for `config('cookie.*')`
- `tests/Unit/Managers/`, `tests/Unit/Session/`, `tests/Support/` — unit coverage, transient stubs, and a recording handler double
- `docs/sessions.md` — new documentation page
- No new dependencies. No breaking changes: every existing code path, including both validation error responses, behaves exactly as it does today
