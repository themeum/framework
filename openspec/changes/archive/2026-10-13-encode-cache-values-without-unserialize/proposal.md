## Why

The WordPress.org plugin review flags any `unserialize()` call that does not pass
`['allowed_classes' => false]`, and `FileStore::read_payload()` currently calls it with no class
restriction. Adding `allowed_classes => false` on its own would silently break the cache: every
cached object would come back as a `__PHP_Incomplete_Class`. The envelope would still validate,
so the caller would get a broken object instead of a miss. The queue already solved the same
problem with `ValueCodec`, a JSON tree codec with a class allowlist. The cache should reuse it
rather than grow a second mechanism.

## What Changes

- Move the job-independent part of `Framework\Queue\ValueCodec` to a new
  `Framework\Supports\ValueCodec`. It takes its allowlist from the constructor and raises errors
  through overridable factory methods, so it never names `QueueException`.
- `Framework\Queue\ValueCodec` becomes a subclass that adds only the job-specific parts: the root
  job class, `ModelIdentifier`, `for_job()`, and `QueueException` errors. Its public API and the
  queue's stored format do not change.
- Add `Framework\Exceptions\ValueCodecException` for codec errors raised outside the queue.
- Both persistent cache drivers (`file` and `database`) store the envelope as JSON, and the value
  goes through the codec. No cache store calls `unserialize()`, and WordPress only ever
  unserializes a plain string for the `database` driver's transients.
- New optional top-level config key `cache.allowed_classes`, which lists classes to allow on top
  of the built-in set. The built-in set is `stdClass`, `DateTime`, `DateTimeImmutable`,
  `DateInterval`, Somoy dates, models, and the two collection classes.
- **BREAKING**: storing an object whose class is not allowed now throws a `ValueCodecException`
  from `put()`, `forever()`, `remember()`, `flexible()` and the other write paths. Before, any
  serializable object was accepted. The `array` driver is unaffected because it never serializes.
- Entries written in the old serialized format read as misses. No migration is needed, and the
  file driver's sweep removes the old files.
- All new classes and methods carry `@since 3.2.4`.

## Capabilities

### New Capabilities
- `cache-value-encoding`: how the persistent cache drivers encode stored values without PHP
  object deserialization, which classes may be restored, and what happens to values and stored
  data the encoding rejects.

### Modified Capabilities
<!-- None. The `caching` capability still lives in the unarchived `add-cache-support` change, not
     in `openspec/specs/`, so the new rules are captured as their own capability instead of a
     delta against it. `queue-payload-encoding` is not affected: the queue's behaviour and
     stored format do not change. -->

## Impact

- **Code**: `src/Queue/ValueCodec.php` (split), new `src/Supports/ValueCodec.php`, new
  `src/Exceptions/ValueCodecException.php`, `src/Cache/Stores/FileStore.php`,
  `src/Cache/Stores/DatabaseStore.php`, and `src/Cache/CacheManager.php` (passes the configured
  codec to the stores).
- **API**: `FileStore` and `DatabaseStore` constructors gain an optional trailing codec
  parameter (additive). Custom drivers registered through `extend()` are untouched.
- **Consumers**: plugins that cache objects of their own classes must list those classes in
  `cache.allowed_classes`. Caching a `WP_Post`, `Paginator`, closure or similar now throws on
  write.
- **Performance**: scalars and lists of scalars skip the reflection path in the codec. Cached
  objects are encoded and decoded through reflection, which is slower than native
  `serialize()`.
- **Docs**: `docs/cache.md` (configuration, drivers, "Where this differs from Laravel").
