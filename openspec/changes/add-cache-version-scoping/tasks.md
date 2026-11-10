## 1. Test support

- [x] 1.1 Make the `update_option` stub in `tests/Support/StubsWordPressFunctions.php` record its
  `$autoload` argument in `$GLOBALS['framework_test_option_autoload'][$name]`, and reset that
  global in `CacheTestCase::reset_cache_globals()`. Verify: `composer test:unit` is still green.

## 2. Failing tests (write first, run, confirm each fails for the right reason)

- [x] 2.1 Create `tests/Unit/Cache/VersionScopedCacheTest.php` (extends `CacheTestCase`; uses
  `ConfigurableCacheManager::set_stores()` and `$this->app->use_version()`; simulates a new
  request with a fresh manager plus `MemoTable::clear()`).
- [x] 2.2 Test: `version_scoped => true`: a value written under version A is not readable after
  the version changes to B, and B is recorded.
- [x] 2.3 Test: `version_scoped => false`: the value is still readable after A → B, and no
  `cache_app_version_*` option is written.
- [x] 2.4 Test: the option is missing but a value exists (upgrade path). The first resolve
  flushes, then writes the option with autoload `true`.
- [x] 2.5 Test: the version is unchanged. No flush: `<prefix>cache_version` keeps its value and the
  entry stays readable.
- [x] 2.6 Test: a network store (`network => true`) reads and writes a site option, and no plain
  option is written.
- [x] 2.7 Test: after a version flush of a database store, the superseded namespace version is in
  `<prefix>cache_stale_versions`.
- [x] 2.8 Test: a custom driver (via `extend()`) whose `flush()` returns `false` leaves the
  recorded version unchanged.
- [x] 2.9 Test: a `CacheFlushed` listener that calls `store()` on the same store does not cause
  a second flush (the namespace version rises by exactly one).
- [x] 2.10 Test: a `file` store with `version_scoped => true` that falls back to a `database`
  store with `version_scoped => false` flushes nothing and records no version under either name.
- [x] 2.11 Test: when the application exposes no `version()`, nothing is flushed or recorded.
- [x] 2.12 Run `composer test:unit -- --filter VersionScopedCacheTest`. Verify every new test
  fails on missing behaviour (not on a setup error), except 2.3/2.10/2.11, which may already pass.

## 3. Implementation

- [x] 3.1 Add `'version_scoped' => false` to the `database` and `file` entries of
  `CacheManager::default_configuration()`.
- [x] 3.2 Add protected `application_version_option(string $name)` returning
  `$this->prefix() . 'cache_app_version_' . $name` (docblock, `@since 3.3.1`).
- [x] 3.3 Add protected `scope_to_application_version(Repository $repository)` per design D4/D5:
  - read the name from `get_name()`;
  - read `version_scoped`/`network` from `store_config()`;
  - guard on `method_exists(app(), 'version')`;
  - compare as strings, with a missing option as `null`;
  - flush;
  - write the option only when the flush returns `true` (`update_option(..., true)` or
    `update_site_option()`).

  It needs a full docblock and `@since 3.3.1`.
- [x] 3.4 In `CacheManager::store()`, memoise `resolve($name)` into `$this->stores[$name]` first,
  then call `scope_to_application_version()` on it (design D2).
- [x] 3.5 Verify: `composer test:unit -- --filter 'VersionScopedCacheTest|CacheManagerTest|FileStoreTest|DatabaseGcTest'`
  is green.

## 4. Documentation

- [x] 4.1 In `docs/cache.md` §10, add `'version_scoped' => false` to the `database` and `file`
  snippet entries with a short `//` note.
- [x] 4.2 In `docs/cache.md` §10, add a `### version_scoped` subsection covering:
  - what it does (a cold cache when the app version changes, the missing option counting as a
    change, once per store per request, no extra query);
  - when to turn it on (stores holding values whose shape your code produces);
  - the costs (a cold cache after every update; rate-limiter counts reset when the limiter uses
    the same store);
  - the caveat that every `database`-driver store, and every `file` store on the default path,
    shares one namespace, so scoping one flushes all of them, a limiter store on the same driver
    included;
  - that it has no effect on `array`.
- [x] 4.3 In `docs/cache.md` §13, add one line saying `version_scoped` has no Laravel equivalent.
- [x] 4.4 In `example/config/cache.php`, add `'version_scoped' => false` to the `database` and
  `file` stores, with a `/* */` block comment in the style of `forever_ttl` and `network`, saying
  the same thing as 4.2.
- [x] 4.5 Verify: the docs, the example config and `default_configuration()` agree on the key,
  the default and the stores it appears on.

## 5. Verification

- [x] 5.1 `composer phpcs && composer test:unit`: both green, full suite.

## 6. PR draft

- [x] 6.1 Write `openspec/changes/add-cache-version-scoping/pr.md` with a title and a body
  containing:
  - the problem statement (the 1.0.0 → 1.0.1 `slug` `TypeError`);
  - the three reasons for a flush rather than a version prefix (stale-version queue lost,
    orphaned `forever()` rows and file directories, `flush()` already handles it);
  - why it is opt-in;
  - the shared-namespace caveat;
  - **known issue:** `CacheManager::lock()` derives its prefix from `get_prefix()`, which includes
    the namespace version, so any flush, this one included, makes locks held at that moment
    invisible to new requests;
  - **note:** concurrent first requests may both flush, the namespace version rises by two, this
    is safe, and no lock was added.
- [x] 6.2 Do not commit, push or open the PR. Wait for Sajeeb to ask, then use `pr.md`.
