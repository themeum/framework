## Context

See proposal.md (Why) for the motivation, and specs/cache-version-scoping/spec.md for the
requirements.

Current state that shapes the approach:

- `CacheManager::store()` resolves a store once per request through `resolve($name)` and memoises
  the repository in `$this->stores[$name]`. `memo()` goes through `store()`, and so does the rate
  limiter (`RateLimiter::cache()`).
- `Repository::flush()` clears the `MemoTable` for the store, calls `Store::flush()`, and fires
  `CacheFlushed` when the flush returns `true`.
- `DatabaseStore::flush()` increments `<prefix>cache_version` and queues the old version in
  `<prefix>cache_stale_versions` for `gc()`. `FileStore::flush()` deletes its directory.
- `DatabaseStore` takes its scope from the `network` constructor flag and reads options through
  `get_site_option()` / `get_option()` (`read_option()`).
- `create_file_repository()` catches a store that cannot run and returns
  `$this->store($fallback)`. That repository carries the **fallback's** name.
- Every `database`-driver store is built from `$this->prefix()` alone, and so is the default `file`
  directory. Neither includes the store name, so all stores on one driver share one namespace.
- `Application::version()` returns a string (default `'1.0.0'`, set with `use_version()`). A
  container that is not a `Framework\Application` may have no `version()`.

## Goals / Non-Goals

**Goals:**

- Flush a scoped store at most once per version change, in the first request that uses it.
- Add no database query in the steady state.
- Keep stores that are not scoped exactly as they are.

**Non-Goals:**

- Per-store namespaces for stores that share a driver. This is documented as a caveat, not fixed.
- Guarding concurrent first requests with a lock. Two requests may both flush, and the namespace
  version then rises by two. This is safe.
- Changing how `lock()` derives its prefix. This is reported as a known issue in the PR.

## Decisions

### D1. Flush through the existing namespace, not a version in the prefix

The proposal records the reasoning ("Why a flush rather than a version in the key prefix"). The
key point is that `flush()` already keeps the `gc()` queue, the `forever()` rows and the file
directories consistent, and a prefix change would bypass all three.

### D2. Cache the repository first, then check

```php
if (!isset($this->stores[$name])) {
    $this->stores[$name] = $this->resolve($name);

    $this->scope_to_application_version($this->stores[$name]);
}
```

`Repository::flush()` fires `CacheFlushed`. If the repository were checked before it is memoised,
a listener that calls `Cache::store()` for the same store would resolve it again and flush again,
or loop. Memoising first makes a re-entrant call return the same instance with no check.
*Alternative rejected:* check, then cache. It matches the brief's wording more literally, but the
re-entrancy above makes it unsafe.

### D3. The check takes the repository and reads the store name from it

The method signature is `scope_to_application_version(Repository $repository)`. It reads
`$repository->get_name()` and `$this->store_config($that_name)`. For a file store that fell back,
the name is the fallback's, so the fallback's `version_scoped` and `network` apply. The nested
`store($fallback)` call has already checked that store, so the outer check finds the recorded
version equal to the current one and does nothing. No special case is needed.
*Alternative rejected:* passing `$name` from `store()`. That would check the fallback repository
under the file store's name and config, which the brief rules out.

### D4. Method shape

- `protected`, `@since 3.3.1`, `@return void`.
- Steps:
  1. Read `$config = $this->store_config($name)`.
  2. Return early unless `($config['version_scoped'] ?? false) === true`.
  3. Read `$app = app()`. Return early unless `method_exists($app, 'version')`.
  4. Read the recorded version with default `null`, so that a missing option and a stored empty
     string stay distinguishable. Compare with `(string) $app->version()` as strings. Any
     difference counts, including a downgrade.
  5. If `$repository->flush()` returns `true`, write the version: `update_option($option, $version,
     true)` for a site store, or `update_site_option($option, $version)` for a network store.
- The option name is built by a small protected helper,
  `application_version_option(string $name)`, which returns
  `$this->prefix() . 'cache_app_version_' . $name`. The test reads the name from it, and a
  subclass can override it.
- Scope comes from `$config['network'] ?? false`, not from the store object, so custom drivers
  behave the same way.

### D5. Record the version only after a successful flush

A `false` result leaves the option unchanged, so the next request retries. Exceptions are not
caught. The built-in stores cannot fail here, and a custom driver whose flush throws should
surface the error rather than serve old-shape data silently. *Alternatives rejected:* always
writing the option, which marks a stale cache as current; and catching and logging, which adds
error handling for a case the built-in stores cannot hit.

### D6. Configuration and docs

- `default_configuration()` adds `'version_scoped' => false` to `database` and `file` only.
  It is pointless on `array`, which lives for one request.
- `example/config/cache.php` gets a `/* */` block comment on the key in both persistent stores,
  in the style of `forever_ttl` and `network`.
- `docs/cache.md` §10 gets the key in the snippet and a `### version_scoped` subsection covering:
  - when to turn it on;
  - the cold-cache cost after each update;
  - the rate-limiter reset;
  - the shared-namespace caveat.
- `docs/cache.md` §13 gets one line saying Laravel has no equivalent.

### D7. Tests

- New file `tests/Unit/Cache/VersionScopedCacheTest.php`, extending `CacheTestCase`.
- Uses `ConfigurableCacheManager::set_stores()` and `$this->app->use_version()`.
- Each "request" is simulated with a fresh `CacheManager` (or `forget_resolved()` plus
  `MemoTable::clear()`), so stores re-resolve.
- The `update_option` stub ignores `$autoload` today. To assert the option is autoloaded, the stub
  records it in `$GLOBALS['framework_test_option_autoload']`, which `CacheTestCase` resets.
- The failure case uses a custom driver registered through `extend()`, whose `flush()` returns
  `false`.
- The re-entrancy case registers a `CacheFlushed` listener that calls `store()` on the same store,
  using the event mechanism `CacheEventsTest` uses.
- The fallback case needs no extra setup. The test process has no direct filesystem, so a `file`
  store always diverts to its fallback, as
  `FileStoreTest::test_the_store_diverts_to_its_fallback_when_direct_access_is_unavailable` relies on.

## Risks / Trade-offs

- [Every `database`-driver store shares one namespace, so scoping one store flushes all of them,
  including a separately configured limiter store on that driver] → Documented in `docs/cache.md`
  and the example config. To keep its counts, the limiter needs a store on another backend.
- [Any flush, this one included, changes the default store's `get_prefix()`, so locks held at that
  moment become invisible to new requests] → Known issue, listed in the PR. Not changed here.
- [Concurrent first requests both see the old version and both flush, so the namespace version
  rises by two] → Safe: the result is still a cold cache with every superseded version queued.
  No lock is added.
- [A custom driver whose flush keeps returning `false` is retried on every request] → This is
  intended: a stale-shape cache is worse than a repeated flush attempt.

## Migration Plan

No migration. The setting defaults to `false`. A consumer that enables it gets one flush on the
first request after deploying, because the missing option counts as a change. Rollback means
setting it back to `false`. The recorded option stays behind and is harmless.
