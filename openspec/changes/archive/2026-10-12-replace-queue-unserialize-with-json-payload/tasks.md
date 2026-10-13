## 1. Codec

- [x] 1.1 Add a value codec in `src/Queue/` that encodes and decodes the tagged JSON tree (scalars, lists, keyed arrays, binary strings, non-finite floats, reserved-key collisions)
- [x] 1.2 Add object support: class plus mangled property map, rebuilt with `newInstanceWithoutConstructor()`; reject cycles and references at encode
- [x] 1.3 Add built-in handlers for `DateTimeInterface`, `ModelIdentifier`, `stdClass`; check framework `Model`/`Collection` state and decide property-map vs API rebuild (see design Risks)
- [x] 1.4 Enforce the allowlist on decode (built-ins plus the job's `allowed_classes`) before any instantiation; throw `QueueException` otherwise
- [x] 1.5 Add `QueueException` factories for unsupported-at-dispatch values

## 2. Payload and jobs

- [x] 2.1 Rewrite `Payload::encode()` to store the codec output with a `format` marker; use `__serialize()` when the job defines it, else reflection
- [x] 2.2 Rewrite `Payload::restore()` with no `unserialize()`; map `TypeError`/`ReflectionException` to `invalid_payload`; fail rows without the marker with the re-dispatch message
- [x] 2.3 Read the job's `allowed_classes` from the class defaults (not declared by `Queueable`, which would be a PHP 7.4 fatal for redeclaring jobs) and document it
- [x] 2.4 Verify `SerializesModels` round-trips through the codec; adjust only if a test shows a gap
- [x] 2.5 Fix the `Payload` file docblock and `ShouldQueue` docblock claims about protection before `unserialize()`
- [x] 2.6 Confirm `Worker::process()` fails (not crashes) on each new exception

## 3. Tests

- [x] 3.1 Update `PayloadTest` for the JSON envelope; keep the round-trip, record-not-persisted and malformed-envelope cases
- [x] 3.2 Add codec tests for each edge case in the spec (binary string, INF/NAN, colliding keys, nested keyed arrays, DateTime zone and microseconds)
- [x] 3.3 Add a tamper test: a row naming a class with a `__destruct`/`__wakeup` that records a side effect proves the class is never instantiated
- [x] 3.4 Add tests for dispatch-time failure (`WP_Post`-like object, closure) and for declared `allowed_classes`
- [x] 3.5 Add a typed-property mismatch test
- [x] 3.6 Re-run `SerializesModelsTest` and `WorkerTest` and fix fixtures that hold plain objects

## 4. Docs and verification

- [x] 4.1 Update `docs/queues.md`: "Job state is frozen at dispatch", `allowed_classes`, and a "Where this differs from Laravel" bullet
- [x] 4.2 Grep `src/` for `unserialize(` and confirm none remain in `src/Queue/`
- [x] 4.3 Run `composer phpcs && composer test:unit`
