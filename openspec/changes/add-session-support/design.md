## Context

See `proposal.md` — Why. The design-relevant constraints are the ones this framework has that Laravel does not:

- **WordPress page caching.** Varnish, Batcache, WP Rocket, Cloudflare APO, and every managed host bypass or refuse to store a response carrying an unrecognized `Set-Cookie`, and bypass cache on requests carrying unknown cookies. Laravel emits its session cookie on every `web` request; doing the same here would make the entire front end uncacheable for anonymous visitors after their first page view.
- **Transients are two option rows.** Each transient is `_transient_<key>` plus `_transient_timeout_<key>`. Laravel's `Store::save()` writes unconditionally on every request, including empty sessions from bots. Reproducing that would mean two `wp_options` rows per crawl.
- **Existing framework precedent.** `Framework\Managers\CookieManager` is a single flat service with a lazily-resolved `$defaults`, a queue, and protected seam methods (`headers_already_sent()`, `write()`, `log_warning()`) that tests override. It is registered as a singleton in `CoreServiceProvider::register_managers()`, aliased in `Application::register_base_aliases()`, fronted by a facade, and exposed by a global helper. The session layer follows that shape exactly.
- **Existing flush points.** `SiteRouter` already calls `flush_queued_cookies()` at each dispatch branch; `RedirectResponse::send()` flushes before `wp_safe_redirect()` because that call `exit`s; a `send_headers` action hook and a `rest_post_dispatch` filter hook cover everything else. Session saving reuses the same points rather than inventing new ones.
- **No encrypter.** As documented for cookies, this framework has none. Session payloads are server-side, so this matters less than it does for cookies, but it is still a documented limit rather than a gap to be filled here.

## Goals / Non-Goals

**Goals:**

- Laravel's session verbs and semantics, so a Laravel developer needs no retraining beyond snake_case naming
- Zero cost — no cookie, no storage write, no cache invalidation — for requests that never touch the session
- Persistence with no new table and no garbage-collection cron, per the transient constraint
- One emission path for the session cookie: the existing `CookieManager` queue, not a second `setcookie()` call site
- Swappable storage, so a future Redis or dedicated-table driver is a new class rather than an edit to the manager

**Non-Goals:**

- Session locking or any concurrency guarantee
- Payload encryption or signing
- A CSRF middleware — WordPress nonces stay the enforcement mechanism
- Network-wide sessions on multisite
- Laravel's `block()`, `SessionManager::extend()` driver-registration API, or multiple simultaneous stores

## Decisions

### Lazy start instead of Laravel's eager start

Laravel's `StartSession` middleware starts the session, adds the cookie to the response, and calls `Store::save()` on every `web` request unconditionally. Here, a session starts only on first write (or on an explicit `get_id()` / `token()` / `regenerate()` that needs an identity). Reads before a start return defaults and touch nothing.

**Why:** it is the single decision that keeps the front end cacheable, and it removes bot-driven transient churn entirely. **Alternative considered:** eager start with Laravel parity — rejected because it disables page caching sitewide, which is a much larger cost in WordPress than the API asymmetry it avoids. **Alternative considered:** opt-in per route via a `StartSession` middleware — rejected because it makes `session()` silently no-op outside those routes, which is a worse failure mode than a null identifier.

**Consequence to handle:** `get_id()` returns null until a start is forced, and there is no per-request "current URL" stored for `back()`. Both are addressed below.

### One manager plus handler drivers, not Laravel's Manager/Store split

Laravel separates `SessionManager` (a driver factory) from `Store` (the data API). Here, `Framework\Managers\SessionManager` carries the whole Store verb set directly and holds one handler resolved from `config('session.driver')` behind `Framework\Contracts\SessionHandler`.

**Why:** it matches the `CookieManager` precedent the codebase already has, and it means the facade, the `session()` helper, and `$request->session()` all return the same object with no second layer of indirection. **Alternative considered:** the faithful Laravel split — rejected as an extra layer with no other precedent in this codebase and no current use case for multiple simultaneous stores. **Alternative considered:** an internal switch with no handler contract — rejected because adding a driver would then mean editing the manager.

### Save at the existing cookie-flush points, plus a `shutdown` safety net

`save()` runs in `SiteRouter::send_response()`, the `SiteRouter::dispatch_match()` redirect and template branches, `RedirectResponse::send()`, the `rest_post_dispatch` filter, and a new `shutdown` action hook.

**Ordering matters:** the save must run *before* `flush_queued_cookies()` at each site point, because starting the session queues the identifier cookie — flushing first would emit the response without it.

`save()` is idempotent: it returns immediately when the store was never started or when nothing was modified, so the overlapping coverage costs nothing and the `shutdown` hook is a genuine safety net for admin, admin-ajax, and template paths that bypass the router. **Alternative considered:** `shutdown` only — rejected because it runs after all output, making failures invisible and putting the save out of step with the cookie flush.

### The headers-already-sent rule

If the first write happens after `headers_sent()`, the identifier cookie cannot be emitted. The rule branches on where the identifier came from:

- **From the request cookie** → the client already holds it; persist normally.
- **Generated this request** → the client can never learn it; log a warning through `LogManager::warning()` and skip the write.

**Why:** persisting a payload whose identifier can never reach the client produces two `wp_options` rows that nothing will ever read. **Alternative considered:** always persist and just warn — rejected for that orphaning. **Alternative considered:** throw — rejected because it converts a cosmetic ordering problem into a fatal error on a production page. This mirrors `CookieManager::write()`, which already logs and returns false rather than throwing.

### Identifier format and validation

Identifiers are `bin2hex(random_bytes(20))` — 40 lowercase hex characters — falling back to `wp_generate_password(40, false, false)` only if `random_bytes` throws.

Every identifier arriving from the client is matched against `/^[a-f0-9]{40}$/` before anything else happens; a non-match is discarded and treated as no session at all.

**Why this is load-bearing:** the identifier is concatenated into a transient key, which becomes an `option_name` in a database lookup. The regex is what keeps attacker-controlled bytes out of that path, and it also keeps the key inside WordPress's 172-character transient-name limit (`with_prefix('session_' . $id)` is far below it). It doubles as the defence against a client pinning a chosen identifier.

### Storage keys and the object cache

The persistent handler uses `set_transient` / `get_transient` / `delete_transient` with the key `with_prefix('session_' . $id)`, reusing the existing `Framework\with_prefix()` helper.

Under an external object cache, transients live in Redis/Memcached rather than `wp_options`. That is accepted, not worked around: it is the good half of the transient trade (zero option-table bloat, zero GC concern), and the bad half — a cache flush drops live sessions — is documented as a durability limit. The framework logs a notice when `wp_using_ext_object_cache()` is true and the driver is `database`. **Alternative considered:** `wp_cache_add_non_persistent_groups('transient')` or direct option writes to force durability — rejected because it reintroduces exactly the bloat and cleanup burden transients were chosen to avoid, and fights the host's caching configuration.

### Sliding expiry, conditional cookie re-queue

The transient TTL resets to `config('session.lifetime')` on every save. The identifier cookie is re-queued only when the session was started **and** modified, so a request that merely reads an existing session adds no `Set-Cookie`. `session.expire_on_close` makes it a zero-minute browser-session cookie.

**Why the conditional re-queue:** Laravel refreshes the cookie on every response; here that would add a `Set-Cookie` to read-only requests for no benefit, since the server-side TTL is what actually governs expiry.

### Bookkeeping-only sessions are pruned rather than persisted

`save()` treats a payload containing only `_token`, empty `_flash` bookkeeping, and `_previous` as holding no application data: it destroys any stored payload, expires the identifier cookie, and writes nothing.

**Why:** without this, the single most common way a session gets created — a validation failure flashing errors — leaves the visitor with a session cookie and a stored payload for the full lifetime after the errors have been read and aged away. In Laravel that is harmless; here it means that visitor is cache-bypassed for two hours because of one mistyped form field, and every bot that trips a public form leaves two `wp_options` rows behind. Pruning returns them to cacheable one request later.

**Trade-off accepted:** `token()` alone no longer sustains a session. Given that nothing in this framework enforces the parity token — nonces do the real work — a session existing solely to hold an unenforced token has no value worth the cache cost. **Alternative considered:** Laravel's unconditional write — rejected for the cache-bypass cost. **Alternative considered:** also generating `_token` lazily — rejected as a further deviation with little additional benefit once pruning is in place.

### `back()` uses the WordPress referer, not a stored URL

Laravel's `StartSession` calls `storeCurrentUrl()` on every request so `back()` is always exact. That is a session write per request — precisely what lazy start exists to avoid.

Instead `back($fallback = null)` resolves: `wp_get_referer()` (WordPress's own helper, which checks `_wp_http_referer` then the `Referer` header and validates same-host) → `previous_url()` if a session is *already* started → the supplied fallback → `home_url()`. Resolving never starts a session.

### Errors as plain data with a read-time wrapper

`ValidationException::get_errors()` already returns `['field' => ['message', ...]]`. That array is flashed verbatim; `errors()` wraps it in a read-only `Framework\Supports\ErrorBag` at read time.

**Why:** nothing but plain arrays and scalars ever enters the transient, so renaming or moving a class cannot break sessions already in flight — a real hazard when payloads outlive a deploy by up to the session lifetime. **Alternative considered:** serializing a bag object into the session — rejected for exactly that reason.

### Validation error handling is left alone

`SiteExceptionHandler` and `ApiExceptionHandler` are not touched. A site-route `ValidationException` still ends in a `wp_die()` 422; a REST one still returns 422 JSON. Errors are never written to the session automatically.

**Why:** automatic redirect-back would put every failed submission's errors and input through a transient, turn a validation typo into a stored payload and a cache-bypassing cookie, and change the behaviour of existing applications. Sending errors in the response costs nothing and keeps the failure path free of storage entirely. The flash surface is still there for controllers that want post-redirect-get — they opt in with `back()->with_errors($e->get_errors())->with_input()` — but the framework does not decide that for them.

### Parity token without enforcement

`token()` and `regenerate_token()` exist and the token rotates with the identifier, but no middleware checks it. The docs state plainly that `wp_nonce_field()` / `check_admin_referer()` are the CSRF defence.

**Why:** a second, framework-private CSRF mechanism alongside nonces would need an exclusion list, would break every existing form that sends only a nonce, and would compete with the mechanism WordPress core, the admin, and every other plugin already speak. **Alternative considered:** omitting the token entirely — rejected because `Session::token()` then throws where a Laravel developer expects a value.

## Risks / Trade-offs

- **Lazy start makes `get_id()` null before first write** → Documented, and every method that genuinely needs an identity (`get_id`, `token`, `regenerate`, `invalidate`, `migrate`) forces a start explicitly rather than returning null.
- **Session lost on object-cache flush** → Documented as a durability limit; boot notice when an external object cache is detected with the `database` driver; docs state nothing security-critical may depend on session state alone.
- **Concurrent read-modify-write loses an update** → No locking, matching Laravel's default (its `block()` needs an atomic cache lock this framework lacks). Documented, with options/post meta or a real table recommended for anything needing atomicity. Partially mitigated by skipping unmodified saves.
- **Save ordering regression** → If a future edit moves `flush_queued_cookies()` before the session save at any site dispatch branch, sessions start but the identifier cookie is silently dropped. Covered by a unit test asserting the cookie is queued before the flush at each branch.
- **`shutdown` runs after `exit`** → PHP runs shutdown functions after `exit`, so the safety net still fires on `wp_die()` and redirect paths. Because `save()` is idempotent this causes no double write, but the late-write rule then governs whether the write happens at all — which is the intended behaviour.
- **Pruning discards something a developer expected to keep** → Only bookkeeping keys count as prunable; a single application-written value keeps the session alive. Covered by a test asserting exactly that boundary.
- **Transient name length** → `with_prefix('session_' . $id)` with a 40-char identifier stays far under WordPress's 172-character transient-name limit for any realistic application prefix. Worth an assertion in the handler test rather than a runtime check.
