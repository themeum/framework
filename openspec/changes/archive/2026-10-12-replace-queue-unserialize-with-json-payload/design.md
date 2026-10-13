## Context

`Payload::encode()` wraps `serialize($clone)` in a JSON envelope; `Payload::restore()` calls
`unserialize()` and checks `instanceof` afterwards, which is too late to stop magic methods. See
proposal.md for why the call has to go, not just be restricted. `SerializesModels` already
implements `__serialize()`/`__unserialize()` returning a property map keyed by PHP's mangled names,
with `ModelIdentifier` objects standing in for saved models. `Worker::process()` already routes
`QueueException` from `Payload::restore()` to the failed-jobs path.

Constraint: even without `unserialize()`, `newInstanceWithoutConstructor()` on an attacker-chosen
class still runs `__destruct` when the object is released. The class allowlist is therefore the
actual security boundary, not the absence of `unserialize()`.

## Goals / Non-Goals

**Goals:**
- No `unserialize()` or `maybe_unserialize()` call under `src/Queue/`.
- A forged row can only ever instantiate the declared job class and allowlisted nested classes.
- Existing jobs that hold scalars, arrays, dates and (with the trait) models keep working unchanged.

**Non-Goals:**
- Signing or encrypting payloads. The allowlist makes tampering harmless, so a signature adds no security here.
- Reading rows in the old serialized format. The queue is unreleased, so none exist.
- Changing the Laravel-style `SerializesModels` behaviour (identity storage, relations, missing models).

## Decisions

**1. A tagged JSON value tree.** Scalars and `null` are written as plain JSON. Anything JSON cannot
represent unambiguously is wrapped in an object with a reserved `"@"` key: `{"@":"array","v":[[k,v],...]}`
for arrays with non-list keys or keys that collide with the marker, `{"@":"bin","v":"<base64>"}` for
non-UTF-8 strings, `{"@":"float","v":"INF"}` for non-finite floats, `{"@":"object","c":"<class>","p":{...}}`
for objects. Plain lists stay plain JSON arrays. Alternative: wrap every array, which is simpler but
bloats payloads and makes rows hard to read. Rejected for readability.

**2. Objects are stored as class plus property map, restored without a constructor.** Same trust
model as native unserialize (constructor not run, defaults applied), so behaviour is unchanged for
allowed classes. Properties are keyed by PHP's mangled names, as `SerializesModels` already does, so
parent-private properties with the same name cannot collide.

**3. The job's own state goes through `__serialize()`/`__unserialize()` when it defines them.**
`SerializesModels` therefore needs no structural change; its identifiers are just objects the codec
must support. Jobs without the trait use plain reflection over all non-static, initialized properties.

**4. Allowlist = built-ins plus a job-declared list.** Built in: `DateTimeInterface` implementations
(stored as ISO timestamp with microseconds and zone, rebuilt through their constructor, never by
property assignment), `ModelIdentifier`, framework `Model` and both `Collection` hierarchies, and
`stdClass`. Additional classes come from an `allowed_classes` property that the job itself declares (not `Queueable`, since redeclaring a trait property with a different default is a PHP 7.4 fatal) (mirrors PHP's
own option and Laravel's `serializable_classes`), read from the class named in the envelope, not
from data. Alternative: a marker interface on value objects. Rejected because it cannot cover
third-party classes. A declaration on the job covers any class.

**5. Fail at dispatch, not at run.** Encoding throws `QueueException` for any non-allowed object,
closure or resource, naming job class, property and type. The error then reaches the developer
while they still have the stack, instead of a failed row hours later.

**6. Restoration errors are `QueueException`.** `TypeError` from assigning to typed properties and
`ReflectionException` are caught in `Payload::restore()` and rethrown as `invalid_payload`, so a bad
row never crashes the worker loop.

## Risks / Trade-offs

- [Existing jobs with arbitrary objects stop dispatching] → fail loudly at dispatch with the fix named; document in `docs/queues.md` upgrade note.
- [Framework `Model` graph may hold state that is not safe to reflect over (connections, closures)] → verify during implementation; if it does, store only attributes, original, relations and `exists`, and rebuild through the model API. Adjust the built-in list rather than widening the allowlist.
- [Hand-rolled codec could have round-trip gaps (floats, references, cycles, `SplObjectStorage`)] → reject object cycles and PHP references at encode with a clear error; cover scalars/edge cases with unit tests.
- [Payloads get larger than `serialize()` output] → acceptable; the jobs table column is already `LONGTEXT`.

## Migration Plan

None. The queue has not been released, so no stored rows exist in the old format.
