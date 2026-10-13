## Why

`Payload::restore()` calls PHP `unserialize()` on the job row's `data` field. WordPress.org plugin
review flags that call, and it is also a real hardening gap: the `ShouldQueue` check covers only
the envelope's `job` field, not the classes inside `data`, and `unserialize()` runs magic methods
(`__wakeup`, `__destruct`) before the later `instanceof` check. Anyone who can write a row to the
jobs table (for example through SQL injection in another plugin) can instantiate arbitrary classes
in the worker. Switching to a signed payload would keep the flagged call; removing it is the only
change that satisfies review and closes the gadget-chain path. The queue is new (3.2.x), so the
cost of changing the stored format is smallest now.

## What Changes

- Store a job as a JSON value tree (class name plus property map) instead of a PHP-serialized string.
- Restore a job with `ReflectionClass::newInstanceWithoutConstructor()` and property assignment. No `unserialize()` anywhere in `src/Queue/`.
- Restore nested objects only from an allowlist: built-in types (dates, `ModelIdentifier`, framework models and collections) plus classes a job declares. Any other object makes dispatch fail with a clear exception.
- `SerializesModels` keeps producing its identifier-bearing property map, but the payload codec, not native `serialize()`, carries it.
- Fix the `Payload` file docblock, which claims the class check protects `unserialize()`.
- Update `docs/queues.md`, including "Where this differs from Laravel" and an upgrade note.
- **BREAKING**: a job holding an object that is not allowlisted (a `WP_Post`, an arbitrary value object) can no longer be queued until the class is declared or the job stores an ID instead.

## Capabilities

### New Capabilities
- `queue-payload-encoding`: how a queued job is encoded into and restored from its stored payload, what object types may appear in it.

### Modified Capabilities
- `queue-model-serialization`: the "other properties are untouched" and "nested model" guarantees now depend on the allowlist, and stored identifiers must round-trip through the JSON codec.

## Impact

- Code: `src/Queue/Payload.php`, `src/Queue/Concerns/SerializesModels.php`, `src/Queue/Concerns/Queueable.php` (allowlist declaration), `src/Queue/Worker.php` (error path), `src/Exceptions/QueueException.php`.
- New file(s) in `src/Queue/` for the value codec.
- Tests: `tests/Unit/Queue/PayloadTest.php`, `SerializesModelsTest.php`, plus codec tests.
- Docs: `docs/queues.md`.
- Overlap: the unarchived `add-queue-support` change has a "Payload handling" requirement that says the job is stored "serialized". This change supersedes it; archive `add-queue-support` first or reconcile when syncing.
