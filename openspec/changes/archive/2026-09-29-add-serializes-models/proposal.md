## Why

A queued job is stored with plain `serialize()`, so a model on a job property is frozen with every
attribute and loaded relation it had at dispatch. The payload is large, and `handle()` runs,
possibly days later, against stale data. The docs currently tell developers to pass IDs and re-load
by hand, and they list "No `SerializesModels`" as a gap compared with Laravel. Consumers porting
Laravel habits expect `use SerializesModels;` to work: a model is stored as its identity and is
re-fetched fresh when the job runs.

## What Changes

- Add an opt-in `Framework\Queue\Concerns\SerializesModels` trait.
  - Through `__serialize()`/`__unserialize()`, it replaces each top-level job property that holds a
    saved `Model`, or a `Database\Query\Collection` of saved models, with a small identifier.
  - On restore it re-fetches the rows and re-loads the relations that were loaded at dispatch.
- Add a `Framework\Queue\ModelIdentifier` value class holding the model class, the key or keys, the
  loaded relation paths and the collection class.
- Document the existing `without_relations()` (from `HasRelationships`, which returns a clone with
  its loaded relations cleared) as the way a job opts a model out of relation re-loading. It is the
  PHP 7.4 replacement for Laravel's `#[WithoutRelations]` attribute. No change to Model is needed.
- Missing models:
  - A single model that no longer exists makes restoring throw `ModelNotFoundException`.
  - The worker fails that job immediately, with no retries and no `failed()` call, because the job
    could not be built.
  - A job class that declares `$delete_when_missing_models = true` is deleted silently instead.
- Collections restore with one `where_in` query, in their original order, and silently drop rows
  that no longer exist. Queueing a collection that mixes model classes throws a `LogicException`
  at dispatch.
- Unsaved models, and collections that contain one, are serialized as they are, because there is
  no row to re-fetch.
- The `make:job` stub uses `SerializesModels` by default, and its "pass IDs, not models" guidance
  is updated.
- Update `docs/queues.md`: add a SerializesModels section, and replace the "No `SerializesModels`"
  gap with the precise differences from Laravel.

Not breaking. The trait is opt-in, so existing jobs and payloads that are already queued behave
exactly as before. `dispatch_sync()` and `Queue::fake()` do not serialize and are unchanged.

## Capabilities

### New Capabilities

- `queue-model-serialization`: Serializing framework models and model collections on queued jobs
  as identifiers, and restoring them fresh when the job runs. Covers:
  - the opt-in trait and which property values it converts
  - relation recording and re-loading, and the `without_relations()` opt-out
  - collection restore semantics
  - handling of unsaved models
  - the worker's handling of models that no longer exist, including `$delete_when_missing_models`
  - validation of the stored model class

### Modified Capabilities

None. The `queue` capability from `add-queue-support` has not been synced into `openspec/specs/`
yet, and this change adds behaviour without altering any of its requirements. The worker's new
missing-model path is specified under the new capability.

## Impact

- **New code**:
  - `src/Queue/Concerns/SerializesModels.php`
  - `src/Queue/ModelIdentifier.php`
- **Modified code**:
  - `src/Queue/Worker.php`: catch `ModelNotFoundException` around `Payload::restore()`
  - `src/Console/stubs/job.stub`
- **Docs**: `docs/queues.md`
- **Tests**:
  - `tests/Unit/Queue/`: serialization round trip, collections, the missing-model worker path
  - new stub models and jobs under `tests/Support/`
- **Version**: new public API (`SerializesModels`, `ModelIdentifier`) is `@since 3.2.1`.
- **Dependencies**: none.
