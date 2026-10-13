## Why

The framework has no cache subsystem. What caching exists is ad hoc and private to the
component that needed it: static arrays inside `OptionManager`, PHP files written by
`PolicyDiscovery`/`ListenerDiscovery`, and a transient wrapper buried in
`TransientSessionHandler`. Plugin code built on the framework therefore calls
`set_transient()`/`get_transient()` directly, which means every caller re-solves the same
WordPress problems — and mostly gets them wrong.

Those problems are not theoretical. `get_transient()` returns `false` for both "missing" and
"the value `false`", so caching a negative result produces a cache that misses forever.
Transient names over 172 characters are silently truncated into collisions because WordPress
strips MySQL strict mode. A transient stored with no expiration is autoloaded on every request,
bypassing core's own 150KB autoload guard. None of this is discoverable until it misbehaves in
production.

A first-class cache with Laravel's vocabulary gives plugin authors one correct implementation,
a familiar API, and swappable storage for tests.

## What Changes

- Add a `Cache` facade and a `cache()` helper, following Laravel's method verbs in the
  framework's snake_case (`remember_forever`, `put_many`, `get_multiple`).
- Add a three-layer cache architecture — `CacheManager` → `Repository` → `Store` — mirroring
  the existing `DatabaseManager` → `Connection` → `QueryCompiler` shape, with named stores,
  `store()`, `driver()` and `extend()`.
- Add three drivers: `database` (WordPress transients), `file` (uploads directory), and
  `array` (request-scoped).
- Add `memo()` request-level memoization as a `Store` decorator, with coherence guarantees
  that Laravel's own `memo` driver does not provide.
- Add five cache events (`CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`,
  `CacheFlushed`) dispatched through the existing `EventManager`.
- Add three WP-CLI commands: `cache:clear`, `cache:forget`, `cache:gc`.
- Add an optional `config/cache.php`; the subsystem must work correctly with no config file.
- Add `EventManager::has_listeners()` so an unlistened event costs one `isset()` rather than an
  object allocation on the request hot path.

Not breaking: every addition is new surface. Nothing existing changes behaviour.

Deliberately out of scope: `Cache::lock()` and everything derived from it (`funnel`,
`without_overlapping`, `flush_locks`), tagged cache, the `failover` driver, `sear`, and
`rememberWithWarmth`.

## Capabilities

### New Capabilities

- `caching`: Storing and retrieving values under a key with a time-to-live, across swappable
  storage drivers. Covers the retrieval, storage, retrieve-and-store, removal and introspection
  verbs; TTL semantics; per-request memoization; stale-while-revalidate; multisite scoping; and
  the guarantees and documented limits of each driver.

### Modified Capabilities

None. `EventManager` gains a method but no existing spec covers it, and no existing requirement
changes.

## Impact

**New code** — `src/Cache/` (manager, repository, memo table, four stores, two concerns, five
events, service provider), `src/Contracts/Store.php`, `src/Supports/Facades/Cache.php`, three
classes under `src/Console/Commands/`.

**Modified code** — `src/Application.php` (alias, provider registration, CLI command map),
`src/helpers.php` (the `cache()` helper), `src/Managers/EventManager.php` (`has_listeners()`).

**Depends on** — the existing `Filesystem`/`File` facade for file-store I/O, `EventManager` for
events, `Log` for degraded-mode warnings, `Somoy`/`Date` for `DateTimeInterface` TTLs, and
`OptionManager` semantics for the flush version counter.

**WordPress surface** — reads and writes transients (and site transients when a store is
configured as network-wide), writes into the uploads directory, and registers one WP-Cron event
for file-store garbage collection.

**No dependencies added.** PHP 7.4 floor unchanged.
