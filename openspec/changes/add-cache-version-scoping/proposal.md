## Why

A consuming plugin caches values whose shape its own code produces (for example a remote JSON
list after the plugin normalises it). When a plugin update changes that shape, the cache key stays
the same, so the new code reads an entry in the old shape and fails. In a real case, version 1.0.1
read a list cached by 1.0.0 that had no `slug` field, and the WordPress plugin update check crashed
with a `TypeError`. Every cache entry can be rebuilt from its source, so after a version change the
correct state is a cold cache.

## What Changes

- New per-store configuration key `cache.stores.<name>.version_scoped` (bool, default `false`).
  It is present on the built-in `database` and `file` stores. It has no useful effect on `array`.
  Any store, custom drivers included, may set it.
- When a store with `version_scoped => true` is first resolved in a request, the framework
  compares the application version with the version recorded for that store. If they differ, or if
  no version is recorded yet, it flushes the store through the existing namespace flush and then
  records the current version.
- The recorded version is an autoloaded option named `<app prefix>cache_app_version_<store name>`.
  It is a site option when the store is `network => true`.
- The version is recorded only when the flush reports success, so a failed flush is retried on the
  next request.
- Docs: `docs/cache.md` gets the configuration key and a section explaining when to turn it on
  and what it costs. That section states plainly that every store on the `database` driver shares
  one namespace, so the flush also covers a rate-limiter store on the same driver. The annotated
  `example/config/cache.php` gets the key and a matching comment.
- No breaking change: the setting is opt-in, and with it off, behaviour is unchanged.

### Why a flush rather than a version in the key prefix

- `DatabaseStore` builds `cache_version`, `cache_stale_versions` and `cache_counters` from the
  same prefix. A version in the prefix moves them too, so the previous version's stale-version
  queue is lost and `gc()` never removes those rows.
- Rows under an old prefix are in no `gc()` queue. Entries written with `forever()` (TTL
  `forever_ttl`, ten years by default) would stay behind after every release. `FileStore` would
  leave one orphaned directory per release.
- `flush()` already handles all of this. `DatabaseStore::flush()` increments the namespace version
  and queues the old one for `gc()`. `FileStore::flush()` deletes its directory. A persistent
  object cache evicts the old namespace by itself.

### Why opt-in

This is a general-purpose library. A consumer may keep values in the cache that it cannot rebuild,
so a flush the consumer did not ask for would change behaviour. The rate limiter also counts in
the default store when `cache.limiter` is `null`, and a flush resets its counts to zero.

## Capabilities

### New Capabilities

- `cache-version-scoping`: opt-in per-store flushing of the cache when the application version
  changes, including how the recorded version is stored, scoped to the network and retried.

### Modified Capabilities

<!-- none -->

## Impact

- `src/Cache/CacheManager.php`: `store()` runs a new protected check after resolving, and
  `default_configuration()` gains `version_scoped => false` on `database` and `file`.
- `tests/Unit/Cache/VersionScopedCacheTest.php`: new test file.
- `docs/cache.md` and `example/config/cache.php`: documentation.
- Runtime cost when enabled: one read of an autoloaded option per store per request, so no extra
  query. On a version change there is one flush and one option write.
- Known, unchanged: `CacheManager::lock()` builds its lock prefix from the default store's
  `get_prefix()`, which includes the namespace version. Any flush, this one included, therefore
  makes locks held at that moment invisible to new requests. This is reported, not fixed here.
