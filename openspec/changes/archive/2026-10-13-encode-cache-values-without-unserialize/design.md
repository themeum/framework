## Context

See `proposal.md` (Why) for the motivation. This section records only the current code that
shapes the approach.

- **`Framework\Queue\ValueCodec`** (since 3.2.2) already turns any value into a JSON-safe tagged
  tree and restores it, checking the class allowlist before creating an object. Objects are
  created without their constructor and filled through `__unserialize()` or reflection. Three
  parts of it are tied to jobs:
  - the `$root` job class, which is always allowed;
  - `ModelIdentifier`, which is always allowed;
  - every error is a `QueueException`. `Worker` catches `QueueException` to fail a job, so the
    queue must keep that type.
- **`Payload`** is the codec's only caller. It calls `ValueCodec::for_job($class)->encode()` and
  `->decode()`, and wraps the result in a JSON envelope with `wp_json_encode()`.
- **`FileStore`** writes `GUARD . serialize($entry)` and reads it back with `unserialize()`.
  `gc()` reads every inspected file through the same `read_payload()`, but only needs the
  envelope's expiry.
- **`DatabaseStore`** hands the envelope array to `set_transient()`, so WordPress serializes it
  and unserializes it with no class restriction. The envelope passes through two helpers,
  `read_transient()` and `write_transient()`, which handle only envelopes. One of their callers
  is the atomic counter's mirror write, `sync_counter_envelope()`.
- **`Entry::read()`** already turns any raw value that isn't a valid envelope for the requested
  key into a miss.
- **`Model`** state is plain data (`attributes`, `original`, `relations`, `casts`, …), so models
  and their loaded relations can be encoded as they are.

## Goals / Non-Goals

**Goals:**
- One codec implementation shared by the queue and the cache.
- The queue's behaviour, stored format, exception types and the public API of
  `Framework\Queue\ValueCodec` stay exactly as they are.
- The `file` and `database` drivers behave the same way for every value.

**Non-Goals:**
- `ArrayStore`, `MemoizedStore` and the lock implementations. They keep values in memory or store
  scalars only.
- `OptionManager`'s `maybe_unserialize()`, and other `unserialize()` call sites that already pass
  `allowed_classes => false` (`Sanitizer`, `HasAttributes`).
- A per-store allowlist. One list applies to every store; see Decision 4.
- Signing or encrypting entries.

## Decisions

### 1. Split by inheritance: the base in `Supports`, the queue as a subclass

`Framework\Supports\ValueCodec` keeps everything that isn't about jobs:
- the tag format;
- encoding and decoding of every type;
- the reflection helpers;
- the built-in allowlist (`stdClass`, models, both collection classes, plus `DateTime`,
  `DateTimeImmutable` and Somoy dates through the date check);
- the extra classes passed to its constructor.

`Framework\Queue\ValueCodec extends` it and changes only this:
- it keeps its `(string $root, array $allowed_classes = [])` constructor and `for_job()`;
- it overrides `is_allowed()` to also accept the root job class and `ModelIdentifier`;
- it overrides two protected error factories to return `QueueException`.

*Alternatives considered:*
- **Make `$root` nullable and call the queue codec from the cache.** This leaves cache errors
  thrown as `QueueException` and ties the cache to the queue namespace.
- **Copy the codec into `Cache`.** Two copies of security-relevant code would drift apart.
- **Composition, with the queue codec wrapping the base.** This needs as much code as
  inheritance, and breaks consumers that already subclass `Framework\Queue\ValueCodec` and
  override its protected methods. The CLAUDE.md rule "never `final`, consumers subclass freely"
  makes that a real concern.

### 2. Errors go through overridable factories and a new `ValueCodecException`

The base codec never uses `new`/`throw` with a concrete exception class. It calls
`$this->unsupported_value($path, $type)` and `$this->invalid_payload($reason)`, which return a
`Framework\Exceptions\ValueCodecException` built by static factories of the same names. The queue
subclass overrides both to return the matching `QueueException` factories, so its messages and
types are unchanged. The base `unsupported_value` message names the type and the path, and
points at `allowed_classes`. That key name is the same for the job property and the cache
config, so one message serves both.

`ValueCodecException` extends `RuntimeException`, matching `QueueException`.

### 3. Only `value` goes through the codec; the envelope stays plain JSON

The stores write `wp_json_encode()` of the envelope with `value` replaced by
`$codec->encode($value, $key)`. Passing the key as the codec's `$path` gives error messages such
as `[user:42->profile]`.

Reading happens in two steps:
1. `json_decode($raw, true)`, then `Entry::read()`. This rejects anything that is not this key's
   envelope.
2. Only then `$codec->decode($envelope['value'])`, inside `try { … } catch (Throwable $e)`. Any
   failure is a miss.

The `Throwable` catch is deliberate. Decoding into a typed property of an allowed class can
raise a `TypeError`, and a cache must never fail a request (an existing `caching` requirement).

Encoding the whole envelope as well would wrap its keyed array in pair lists for no gain. It
would also mean decoding, and possibly instantiating, a value before checking that the entry
belongs to the requested key.

This also means `FileStore::gc()` only needs step 1 to check expiry. The sweep never decodes a
value and never creates an object.

### 4. One `cache.allowed_classes` key shared by every store

`CacheManager::value_codec()` builds a `Supports\ValueCodec` from
`config('cache.allowed_classes', [])`, and `create_file_repository()` and `create_database_store()`
each pass one to their store. A store is resolved once per name, so this is cheap, and it avoids
a cached codec going stale across `forget_resolved()`. Each store takes the codec as a new
optional, trailing `?ValueCodec $codec = null` constructor parameter. When it is null,
the store uses `new ValueCodec()` with built-ins only, so code that builds a store directly keeps
working.

The list is global because `guard_supported()` can silently move a consumer from `file` to its
`fallback` store. A per-store list could then reject on one host a value that works on another.

### 5. Shared envelope I/O lives in a cache concern

A new `Framework\Cache\Concerns\EncodesEntries` trait holds:
- `$codec`;
- `encode_entry(array $entry, string $key)`, which returns a string, or `false` when JSON
  encoding fails;
- `decode_entry($raw, string $key)`, which parses a stored string and returns an array or null;
- `restore_entry($envelope, string $key)`, which takes an already parsed envelope, checks it
  with `Entry::read()` and decodes its value.

`FileStore` uses `encode_entry()` in `write_payload()` and `restore_entry()` in `get_entry()`. Its
`read_payload()` returns the parsed envelope with the value still encoded, so `gc()` is unchanged.

`DatabaseStore` reads through `decode_entry()` in `get_entry()`. Writes go through a new
`write_entry($key, $entry, $seconds)` helper that encodes and then calls `write_transient()`. Its
four former `write_transient()` call sites use it, `sync_counter_envelope()` included.
`read_transient()` and `write_transient()` themselves stay raw transient I/O. `increment()`,
`flexible()` and the counter mirror needed no other change.

### 6. No envelope format bump

The old and new storage forms can't be confused:
- an old file fails `json_decode`;
- an old transient comes back from WordPress as an array, not a string, and `decode_entry()`
  rejects anything that isn't a string.

`Entry::FORMAT_VERSION` stays `1`, so `ArrayStore` and the envelope helpers are untouched.

## Risks / Trade-offs

- **[A consumer caches objects of their own classes]** These puts start throwing after the
  upgrade. → The exception names the key, path and class and points at `cache.allowed_classes`.
  The change is called out as **BREAKING** in the proposal and documented in `docs/cache.md`. A
  throw is caught the first time the code runs, where a silent `false` would not be.
- **[Cached objects are slower to encode]** Reflection-based encoding costs more than native
  `serialize()`, on the hot path. → Scalars and lists of scalars return from `encode()` before
  any reflection, and they are most cache traffic. Objects pay the same cost the queue already
  pays. Profile if it shows up; no optimisation now.
- **[Autoloading from tampered data]** `is_a($class, Model::class, true)` autoloads the class name
  in a stored entry before rejecting it. → Names must match the identifier pattern first, and the
  autoloader can only load files the consumer already ships. The queue has the same exposure;
  this change adds no new class of risk.
- **[An allowed class changes shape between deploys]** A typed-property mismatch on decode is
  caught, so the entry reads as a miss. Native `unserialize()` here would have produced a
  half-initialised object.
- **[Cold cache after upgrade]** Every existing entry becomes a miss once. → This is the expected
  cost of a cache format change, and it is no different from `flush()`. Old files are removed by
  the sweep as it reaches them.

## Migration Plan

Ship in 3.2.4. No data migration: old entries read as misses and are overwritten or swept.
Consumers caching objects outside the built-in set add those classes to `cache.allowed_classes`
before upgrading. Rollback means reinstalling 3.2.3. Entries in the new JSON form then fail
`unserialize()` and read as misses in 3.2.3 too, so rolling back is equally safe.
