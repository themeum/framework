## 1. Foundations

- [x] 1.1 Create `src/Contracts/Store.php` — the ten primitive methods (`get`, `many`, `put`, `put_many`, `increment`, `decrement`, `forever`, `forget`, `flush`, `get_prefix`), with the file docblock and `defined('ABSPATH') || exit;` guard every source file carries
- [x] 1.2 Create `src/Cache/Concerns/InteractsWithTime.php` — the single overridable UTC-epoch clock method plus TTL coercion for `int` | `DateTimeInterface` | `DateInterval` | `null`, returning `null` for forever and a negative marker for zero/past values
- [x] 1.3 Create `src/Cache/Concerns/HashesKeys.php` — `hash_hmac('sha256', $blog_id . '|' . $key, wp_salt('nonce'))` with the salt resolved lazily on first use (never in a constructor), a public method to compute a stored key's identifier, and the per-store truncation policy (32 hex for transients, 64 for files)
- [x] 1.4 Add envelope pack/unpack helpers — build the marked array, and treat a non-array, an unmarked array, or a stored `key` mismatch as a miss

## 2. Stores

- [x] 2.1 Implement `src/Cache/Stores/ArrayStore.php` — request-scoped, honouring `expires_at` from the envelope so its TTL behaviour matches the persistent stores
- [x] 2.2 Implement `src/Cache/Stores/DatabaseStore.php` — transient reads/writes through the envelope, versioned key namespace, `forever_ttl` for no-expiry writes, and the `'network' => true` variant switching to `*_site_transient` and network options
- [x] 2.3 Implement `DatabaseStore::flush()` — increment the version counter, then run the `$wpdb` sweep of the previous version's rows only when `wp_using_ext_object_cache()` is false
- [x] 2.4 Implement `src/Cache/Stores/FileStore.php` — uploads path resolution with the salted suffix, two-level `ab/cd/` fan-out, and the four protection files written at directory creation (`index.php`, `.htaccess`, `.php` extension, `<?php exit; ?>` payload guard)
- [x] 2.5 Implement `FileStore` I/O against the `File` facade — `is_file()` pre-check plus a `catch` for the TOCTOU window on reads; temp-path `put()` then `move()` for atomic writes
- [x] 2.6 Implement `FileStore` degradation — lazy `Filesystem` resolution inside `try`/`catch`, `get_filesystem_method() !== 'direct'` check, fallback to the configured store, `Log::warning()` once per request, `_doing_it_wrong()` in dev mode
- [x] 2.7 Implement `FileStore` garbage collection — unlink expired entries on read, plus the bounded sweep that walks a rotating slice of shards using a cursor in an option and a per-run file cap
- [x] 2.8 Implement `src/Cache/Stores/MemoizedStore.php` — a `Store` decorator reading and writing the shared memo table, memoizing misses as well as hits

## 3. Repository and manager

- [x] 3.1 Create `src/Cache/MemoTable.php` — the static per-request table keyed by store name and blog id, with per-key and whole-store invalidation
- [x] 3.2 Implement `src/Cache/Repository.php` retrieval verbs — `get` (closure default), `many`, `get_multiple`, `has`, `missing`, `pull`, and the five typed getters
- [x] 3.3 Implement `Repository` storage verbs — `put`, `set`, `put_many`, `set_multiple`, `add`, `forever`, `touch`, with zero/negative/past TTL forgetting the key and reporting failure
- [x] 3.4 Implement `Repository` counters — `increment`, `decrement` as plain read-modify-write returning the new value, or `false` when the stored value is not numeric
- [x] 3.5 Implement `Repository::remember()` and `remember_forever()`
- [x] 3.6 Implement `Repository::flexible()` — `fresh_until`/`expires_at` window logic, the `shutdown` handler calling `fastcgi_finish_request()`/`litespeed_finish_request()` when available, and the best-effort refresh marker. Omit Laravel's `$lock` parameter
- [x] 3.7 Implement `Repository` removal and introspection — `forget`, `delete`, `delete_multiple`, `flush`, `clear`, `get_store`, `get_name`, `get_prefix`, `get_default_cache_time`, `set_default_cache_time`, `supports_tags()` returning `false`
- [x] 3.8 Add `ArrayAccess` and `Macroable` to `Repository`, and invalidate the memo table from every write path
- [x] 3.9 Implement `src/Cache/CacheManager.php` — per-name repository resolution and memoisation, `store()`, `driver()`, `extend()`, `repository()`, `memo()` returning the same instance per store name, and `__call` forwarding to the default repository
- [x] 3.10 Verify typed-getter coercion and error behaviour against `Illuminate\Cache\Repository` and match it (design.md Open Questions)

## 4. Events

- [x] 4.1 Add `has_listeners(string $event_class): bool` to `src/Managers/EventManager.php`
- [x] 4.2 Create the five event classes under `src/Cache/Events/` — `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`, `CacheFlushed`, each carrying the store name and key
- [x] 4.3 Dispatch them from `Repository`, checking `has_listeners()` *before* constructing the event object, and honouring the per-store `'events' => false` setting

## 5. Wiring

- [x] 5.1 Create `src/Cache/CacheServiceProvider.php` — `register()` binds `CacheManager` as a singleton and throws `InvalidArgumentException` naming the offending driver and config key on an unknown driver; `boot()` registers the GC cron event and the `flexible()` shutdown handler
- [x] 5.2 Create `src/Supports/Facades/Cache.php` with accessor `'cache'` and full `@method static` annotations plus `@see \Framework\Cache\CacheManager`
- [x] 5.3 Register in `src/Application.php` — alias `'cache' => CacheManager::class` in `register_base_aliases()`, and `CacheServiceProvider` in `register_base_service_providers()` *after* `FileSystemServiceProvider`
- [x] 5.4 Add `Framework\cache($key = null, $default = null)` to `src/helpers.php`, modelled on `Framework\session()`, with the array form calling `put_many()` and returning its boolean

## 6. Command line

- [x] 6.1 Implement `ClearCacheCommand`, `ForgetCacheCommand` and `GcCacheCommand` under `src/Console/Commands/`, each extending `CommandBase` and accepting an optional `--store=`
- [x] 6.2 Register `cache:clear`, `cache:forget` and `cache:gc` in the command map in `src/Application.php`

## 7. Configuration

- [x] 7.1 Write `example/config/cache.php` in the heavily-commented, all-keys-optional style of `example/config/session.php`, documenting the object-cache caveat on the `database` driver
- [x] 7.2 Confirm every default is resolved in code so the subsystem works correctly with the config file absent

## 8. Tests

- [x] 8.1 Add `tests/Support/Cache/` fakes — a frozen clock driving the section 1.2 seam, an in-memory transient double building on the existing `$GLOBALS['framework_test_transients']` stubs, and a filesystem double
- [x] 8.2 Test the envelope — round-trip for `false`, `null`, `0` and `''`; `has()`/`missing()` correctness; `remember()` not re-invoking its callback for a falsy cached result
- [x] 8.3 Test TTL coercion across all four accepted types, and that zero, negative and past values forget the key and report failure
- [x] 8.4 Test key handling — a key past the transient limit, two long keys sharing a prefix storing independently, path-unsafe characters, and collision-degrades-to-miss
- [x] 8.5 Test `flush()` — version bump hides all prior entries including a `forever()` entry, and the sweep is skipped under a simulated external object cache
- [x] 8.6 Test `forever()` writes a non-autoloaded option, and that `forever_ttl => 0` restores literal never-expiry
- [x] 8.7 Test `flexible()` fresh, stale and expired windows against the frozen clock
- [x] 8.8 Test `memo()` — repeat reads consult the store once, and a write bypassing the memoized cache is observed by the next memoized read
- [x] 8.9 Test the file store — protection files written, atomic write path, the section 2.6 fallback, and the bounded sweep
- [x] 8.10 Test multisite key scoping across a simulated `switch_to_blog()`, including that the memo table does not leak across sites
- [x] 8.11 Test the events subset, including that nothing is dispatched when `'events' => false`
- [x] 8.12 Test `CacheManager` — named stores, `extend()` with a custom driver, and the error on an unknown driver

## 9. Documentation

- [x] 9.1 Write `docs/cache.md` alongside `docs/migrations.md`, covering the full method surface, the drivers, and configuration
- [x] 9.2 Document the four deliberate divergences from Laravel — `forever()`'s long TTL and why, `flexible()`'s shutdown refresh and best-effort stampede guard, non-atomic counters with `DB::table()` as the exact alternative, and `flush()`'s version-bump semantics

## 10. Verification

- [x] 10.1 `composer phpcs` passes clean, including `SnakeCaseMethodSniff`, `NoPrivateSniff` and the docblock/file-order sniffs
- [x] 10.2 `composer test:unit` green across the new suite and the existing tests
- [x] 10.3 Exercise `put`/`get`/`remember`/`forever`/`flexible`/`memo` and the `cache()` helper against all three stores in `example/`, and confirm no autoloaded `_transient_` option is created by `forever()`
- [x] 10.4 Run `wp cache:clear`, `wp cache:forget <key>` and `wp cache:gc` against the example plugin
