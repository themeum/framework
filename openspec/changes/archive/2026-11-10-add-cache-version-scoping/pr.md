# Add opt-in `version_scoped` cache stores that start cold on app version change

## Problem

A plugin caches values whose shape its own code produces, for example a remote JSON list after
the plugin normalizes it. When a plugin update changes that shape, the cache key stays the same,
so the new code reads an entry in the old shape and fails. In a real case, version 1.0.1 read a
list cached by 1.0.0 that had no `slug` field, and the WordPress plugin update check crashed with a
`TypeError`.

Every cache entry can be rebuilt from its source, so after a version change the correct state is a
cold cache.

## What this adds

- A per-store setting, `cache.stores.<name>.version_scoped` (bool, default `false`). It is added to
  the built-in `database` and `file` stores.
- `CacheManager::store()` now runs a protected check, `scope_to_application_version()`, straight
  after `resolve()`. For a scoped store, the check:
  - reads `<app prefix>cache_app_version_<store name>` (a site option when `network => true`);
  - if that differs from `app()->version()`, or is missing, calls `flush()` on the repository and
    then records the version, autoloaded.
- The version is recorded only when `flush()` returns `true`, so a failed flush is retried on the
  next request.
- The repository is memoised **before** the check runs, so a `CacheFlushed` listener that resolves
  the same store gets the same instance and cannot cause a second flush.
- The store name comes from the repository. A `file` store that diverts to its fallback is
  therefore checked under the fallback's name and configuration.
- `store()` memoises per request, so the check runs at most once per store per request, and only
  in requests that use the store. The option is autoloaded, so the read adds no query.
- A missing option counts as a change. A site that upgrades from a framework release without this
  setting may hold old-shape entries, so it must flush once.

## Why a flush, and not a version in the key prefix

- `DatabaseStore` builds `cache_version`, `cache_stale_versions` and `cache_counters` from the
  same prefix. A version in the prefix moves them too, so the previous version's stale-version
  queue is lost and `gc()` never removes those rows.
- Rows under an old prefix are in no `gc()` queue. Entries written with `forever()` (TTL
  `forever_ttl`, ten years by default) would be left behind by every release, and `FileStore`
  would leave one orphaned directory per release.
- `flush()` already handles all of this. `DatabaseStore::flush()` increments the namespace version
  and queues the old one for `gc()`. `FileStore::flush()` deletes its directory. A persistent
  object cache evicts the old namespace by itself.

## Why opt-in

This is a general-purpose library. A consumer may keep values in the cache that it cannot rebuild,
so a flush the consumer did not ask for would change behaviour. The rate limiter also counts in
the default store when `cache.limiter` is `null`, and a flush resets its counts to zero.

## Caveat: stores share a namespace

Every `database`-driver store is namespaced by the application prefix alone, not by store name,
and so is every `file` store on the default path. Scoping one of them therefore flushes all of
them, a limiter store on the same driver included. This is documented in `docs/cache.md` and
`example/config/cache.php`, not changed here.

## Known issues (not changed in this PR)

- **Locks lose visibility on any flush.** `CacheManager::lock()` builds its lock prefix from
  `$this->store()->get_prefix()`, which includes the namespace version. Any `flush()`, the one this
  setting performs included, therefore makes the locks that requests hold at that moment invisible
  to new requests.
- **Concurrent first requests may both flush.** Two requests can both see the old version and both
  flush, so the namespace version rises by two. This is safe: the cache is still cold, and both
  superseded versions are queued for `gc()`. No lock was added for it.

## Tests

`tests/Unit/Cache/VersionScopedCacheTest.php` has 12 tests:

- a scoped store loses the value after an A → B update, and so does a downgrade;
- an unscoped store keeps it;
- the built-in defaults are off;
- a missing option flushes once and writes an autoloaded option;
- an unchanged version does not flush;
- a network store uses a site option;
- the flushed namespace is in the `gc()` queue, and `gc()` reclaims it;
- a flush that returns `false` leaves the version unchanged;
- a re-entrant resolve from a `CacheFlushed` listener flushes only once;
- a fallback is checked under its own name;
- an app without `version()` is never flushed.

The `update_option` test stub now records its autoload argument.

`composer phpcs && composer test:unit`: 947 tests pass. PHPCS reports no errors; the three warnings
it reports already exist on `main`, in files this PR does not touch.

## Docs

- `docs/cache.md` §10 adds the key to the configuration snippet and a new `version_scoped`
  subsection: what it does, when to turn it on, what it costs, and the shared-namespace caveat.
- `docs/cache.md` §13 notes there is no Laravel counterpart.
- `example/config/cache.php` adds the key with an annotated comment on both persistent stores.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
