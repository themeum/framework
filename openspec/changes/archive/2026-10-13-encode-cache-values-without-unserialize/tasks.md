## 1. Shared codec

- [x] 1.1 Create `src/Exceptions/ValueCodecException.php`, extending `RuntimeException`, with static `unsupported_value(string $path, string $type)` and `invalid_payload(string $reason)` factories. The unsupported-value message names the type and path and points at `allowed_classes`. File header and every docblock carry `@since 3.2.4`.
- [x] 1.2 Create `src/Supports/ValueCodec.php` holding the job-independent body of `Framework\Queue\ValueCodec`:
  - the `TAG` constant;
  - `encode()` and `decode()`;
  - every `encode_*` and `decode_*` method;
  - `read_state()`, `get_properties()`, `mangle()` and `normalize()`;
  - `is_allowed_date()`;
  - `is_allowed()`, covering the identifier pattern, `stdClass`, models, both collection classes and the constructor's extra classes.

  The constructor is `__construct(array $allowed_classes = [])`. Every `throw` goes through new protected `unsupported_value()` and `invalid_payload()` methods that return `ValueCodecException`. Tag the new file `@since 3.2.4`, subpackage `Supports`.
- [x] 1.3 Reduce `src/Queue/ValueCodec.php` to `extends \Framework\Supports\ValueCodec`:
  - keep the `(string $root, array $allowed_classes = [])` constructor and `$root`;
  - keep `for_job()`;
  - override `is_allowed()` to also accept the root and `ModelIdentifier`;
  - override `unsupported_value()` and `invalid_payload()` to return the `QueueException` factories.

  Keep the existing `@since 3.2.2` tags on what remains, and `@since 3.2.4` on the two new overrides.
- [x] 1.4 Verify the queue is unchanged: `composer test:unit -- --filter 'Queue'` passes with no test edits.

## 2. Cache encoding

- [x] 2.1 Create `src/Cache/Concerns/EncodesEntries.php` with:
  - a protected `$codec`;
  - `encode_entry(array $entry, string $key)`, which runs `wp_json_encode` on the envelope with `value` passed through `$this->codec->encode($value, $key)`;
  - `decode_entry($raw, string $key)`, which returns null for a non-string, invalid JSON or `Entry::read()` failure, then decodes `value` inside `try`/`catch (Throwable)` and returns null on any failure.

  Tag it `@since 3.2.4`.
- [x] 2.2 `FileStore`:
  - use `EncodesEntries`, and add an optional trailing `$codec = null` constructor parameter that defaults to `new ValueCodec()`;
  - `write_payload()` writes `GUARD . encode_entry(...)`, letting an encode exception propagate before any temporary file is created;
  - `read_payload()` returns the JSON-decoded envelope array (or null) and never calls `unserialize()`;
  - `get_entry()` decodes the value through `decode_entry()`;
  - `gc()` keeps working off the undecoded envelope.
- [x] 2.3 `DatabaseStore`: use `EncodesEntries`, add the same optional codec parameter, and make `write_transient()` store `encode_entry(...)`. Make `read_transient()` callers go through `decode_entry()`, so a non-string transient (the old array form) is a miss. Confirm `increment()`, the atomic counter mirror and `flexible()` need no further change.
- [x] 2.4 `CacheManager`: build one `Supports\ValueCodec` from `config('cache.allowed_classes', [])`, ignoring non-array values. Pass it to `create_file_repository()` and `create_database_store()`.
- [x] 2.5 Confirm with `grep -rn "unserialize(" src` that every remaining call passes `['allowed_classes' => false]`, and that no cache store calls it.

## 3. Tests

- [x] 3.1 Update `tests/Unit/Cache/FileStoreGcMemoryTest.php` seeding to write entries in the new JSON form rather than `serialize()`.
- [x] 3.2 Add a test double in `tests/Support/Cache/` for a class whose `__construct`, `__wakeup`, `__unserialize` and `__destruct` record that they ran. Add a plain value object double for allowlist tests.
- [x] 3.3 Add `tests/Unit/Cache/ValueEncodingTest.php` covering the spec scenarios against both `FrozenFileStore` and `FrozenDatabaseStore`, with a data provider:
  - scalar, binary, `INF` and keyed-array round trip;
  - a stored `false` stays present;
  - model with a relation round trip;
  - `DateTime`, `DateTimeImmutable` and Somoy round trip;
  - a configured value object round trip, with its constructor not run;
  - an object not on the allowlist throws on `put()` and leaves the previous entry readable;
  - `remember()` propagates the exception and caches nothing.
- [x] 3.4 Add tamper and legacy tests:
  - an entry file rewritten to name the recording class reads as a miss, and the class records nothing;
  - corrupt JSON in a file or transient reads as a miss;
  - an old `GUARD . serialize($entry)` file reads as a miss and is removed by `gc()`;
  - an old array-form transient reads as a miss and is replaced as JSON on the next `put()`;
  - the stored file and transient contain JSON, not serialized data.
- [x] 3.5 Add a `CacheManagerTest` case showing `cache.allowed_classes` reaches both stores, and that an `ArrayStore` still accepts any object.
- [x] 3.6 Run `composer phpcs && composer test:unit` and get both clean.

## 4. Documentation

- [x] 4.1 `docs/cache.md` § Configuration: document `allowed_classes`, the built-in set, and an example adding a value object.
- [x] 4.2 `docs/cache.md` § The drivers: state that `file` and `database` store JSON, which types round trip, that disallowed objects throw on write, and that entries from 3.2.3 and earlier read as misses after upgrading.
- [x] 4.3 `docs/cache.md` § Where this differs from Laravel: add that Laravel caches any serializable object while this cache restores only allowed classes, and why (WordPress.org review rule, object injection).
