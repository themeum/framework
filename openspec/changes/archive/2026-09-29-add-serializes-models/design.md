## Context

See proposal.md for the motivation and specs/queue-model-serialization/spec.md for the required
behaviour. The facts about the current code that shape the approach:

- **Encoding.** `Payload::encode()` serializes a clone of the job with `serialize()`, and
  `QueueManager::push()` calls it from `PendingDispatch::__destruct()`.
- **Restoring.** `Payload::restore()` checks that the envelope's class exists and implements
  `ShouldQueue`. It then calls `unserialize($data, ['allowed_classes' => true])` and checks the
  result's class.
- **The worker.** `Worker::process()` wraps `Payload::restore()` in a try/catch that only catches
  `QueueException` and sends it to `fail_job($record, null, $e)`. That path already fails the job
  immediately without consulting its tries and without calling `failed()`, because there is no job
  instance to call it on. Any other exception thrown during restore escapes `process()`.
- **Model persistence state.**
  - `Model::$exists` is a `protected bool`, set to `true` by `new_for_hydration()` and `save()`.
    There is no public accessor.
  - `HasAttributes::exists()` is protected too.
  - `Model::__call` forwards unknown calls to the query builder, so calling `$model->exists()` from
    outside the class runs a query instead.
- **Model structure.**
  - `Model::$connection` is static per class, so there is no per-instance connection to record.
  - `Model::$relations` is a public array of `name => Model|Collection|null`.
  - `get_relations()`, `unset_relations()` and `without_relations()` are public.
  - `QueryBuilder::with()` supports nested relations.
- **Test doubles.** `ModelTestWpdb` resolves `WHERE col = x` and `WHERE col IN (...)`, which is
  enough for restoring by key and for eager loading.
- **Constraints.** PHP 7.4 syntax only. No `private` or `final`. No `declare(strict_types)`.
  php-scoper prefixes everything, so class names are always referenced through `::class`.

## Goals / Non-Goals

**Goals:**
- Laravel's `SerializesModels` semantics, implemented with PHP 7.4's `__serialize()` and
  `__unserialize()`. Both exist on 7.4 and take precedence over `__sleep()` and `__wakeup()`.
- No change to `Payload`, the envelope format, or jobs that don't use the trait.
- Security at least as strong as the existing payload checks. A stored identifier must never make
  the worker instantiate or query an arbitrary class.

**Non-Goals:**
- Converting models nested inside arrays or objects. Laravel doesn't either.
- `QueueableEntity` and `QueueableCollection` contracts, or support for non-ORM entities.
- WordPress core objects (`WP_Post`, `WP_User`, and so on).
- Pivot and morph relation restoration, and per-instance connections.
- Changing `dispatch_sync()` or `Queue::fake()`, which never serialize.

## Decisions

### 1. One trait, `Framework\Queue\Concerns\SerializesModels`, beside `Queueable`

The trait defines `__serialize(): array` and `__unserialize(array $values): void`, plus protected
helpers for converting values in each direction.

Laravel splits the logic into `SerializesAndRestoresModelIdentifiers`, used by
`SerializesModels`. We keep a single trait because nothing else needs the conversion helpers.
Placing it in `Queue\Concerns` follows `Queueable`'s location.

### 2. How properties are walked

**Serializing.** `__serialize()` walks `ReflectionClass($this)` and each parent class. For every
property:

- Skip static properties, and typed properties that are not initialized
  (`ReflectionProperty::isInitialized()`, available on 7.4).
- Read the value with `setAccessible(true)`.
- Convert it with `get_serialized_property_value()`.
- Store it under PHP's native mangled key: `"\0{Class}\0name"` for private, `"\0*\0name"` for
  protected, and `name` for public.

Walking the parent classes explicitly picks up private properties declared by a parent class,
which `getProperties()` on the child omits. Laravel misses those.

**Unserializing.** `__unserialize()` reverses the process. For each key, it demangles the name,
finds the declaring class's `ReflectionProperty`, restores the value with
`get_restored_property_value()`, and sets it. Keys that no longer match a property are ignored.
This covers a job class that was deployed with a property removed while its payload was still
queued.

**Why mangled keys.** They keep the array identical in shape to what PHP's own `serialize()`
produces. A job that stops using the trait later can still read old payloads, and two properties
with the same name at different levels of the hierarchy can't collide.

**Alternative considered:** `__sleep()`/`__wakeup()`, as older Laravel versions used. Rejected
because `__sleep()` returns names only, so values can't be swapped without mutating the live job.
The dispatching code keeps a reference to that job.

### 3. `Framework\Queue\ModelIdentifier`, a plain value class

It has four public properties:

- `class` (string)
- `id` (a scalar key, or an ordered array of keys for a collection)
- `relations` (array of dotted paths)
- `collection_class` (string, or null for a single model)

It has a constructor and no behaviour. It is serialized natively inside the job's payload, which
`allowed_classes => true` already permits.

We use `instanceof Model` and `instanceof Database\Query\Collection` directly instead of adding
contracts. Only ORM models take part today. Contracts can be added later without breaking anything.

### 4. What counts as a saved model

A model is converted only if both of these are true:

- its protected `$exists` is `true`
- `get_primary_key_value()` is not null

The trait reads `$exists` through a `ReflectionProperty` on `Model::class`, made accessible and
cached in a static.

**Alternatives considered:**
- Adding a public `Model::is_persisted()`. That would be new public API on Model just for this.
- Calling `$model->exists()`. That runs a query through `__call`.
- Checking only the key, as Laravel does. That would convert a `new Model(['id' => 5])` which was
  never saved.

A collection is converted only when every item is a saved `Model` of the same class. If any item
is unsaved, the whole collection is left as it is, as the spec requires. If the items are saved
but belong to more than one class, a `LogicException` is thrown with the message "Queueing
collections with multiple model types is not supported.", as in Laravel. The throw comes from
`serialize()` inside `Payload::encode()`, called from `PendingDispatch::__destruct()`.
Exceptions thrown from a destructor propagate normally outside of shutdown, so the dispatching
statement throws and no row is written.

### 5. Recording relations

`get_queueable_relations(Model $model)` recurses over `$model->get_relations()`:

- Each relation name is recorded.
- If the value is a `Model`, its own relations are recorded with the prefix `name.`.
- If the value is a collection, the nested relations recorded are the **intersection** of every
  item's relations, prefixed the same way. This follows Laravel's
  `Collection::getQueueableRelations()`: a nested path is re-loaded only if every item had it.
- `null` values, from a relation that loaded no row, are still recorded by name.

For a collection property, the relations recorded are the intersection across its items.

The opt-out is the existing `HasRelationships::without_relations()`, which already returns
`(clone $this)->unset_relations()`. Model has no `__clone`, and attributes are arrays, which are
copied by value, so the original keeps its relations. Model is not changed.

### 6. Restoring

**Class validation, before any query.**
- `class_exists($class) && is_subclass_of($class, Model::class)` must be true. Otherwise the
  trait throws `QueueException::invalid_payload(...)`.
- For collections, `collection_class` must be `Database\Query\Collection` or a subclass of it,
  checked the same way.

Throwing `QueueException` means the worker's existing catch fails the job as an invalid payload,
which satisfies the tampering requirement without new worker code.

**A single model.** The trait builds a query with `$class::query()`, adds `->with($relations)`
when there are relations, filters `where($model->get_primary_key(), '=', $id)`, and calls
`->first()`. This is the same path as `Model::find()`. A `null` result throws
`new ModelNotFoundException($class, $id)`.

**A collection.**
- An empty collection is never converted, so it round-trips as it is and restoring it runs no query.
- Otherwise the trait runs `where_in($primary_key, $ids)` with the same `with()`, then keys the
  results by primary key value. It rebuilds the list by mapping the original `$ids` in order and
  skipping missing ones.
- The result is wrapped in `new $collection_class($models)`.

Restoring doesn't need to bypass global scopes or soft deletes, because the ORM has neither.

### 7. The worker's missing-model path

`Worker::process()` gains a second catch around `Payload::restore()`, for
`ModelNotFoundException`.

- **If** `delete_when_missing_models($envelope['job'])` is true: call
  `$this->queue->delete($record->id())` and return. No event, no log, and no failed row, as in
  Laravel.
- **Otherwise:** call `$this->fail_job($record, null, $exception)`. That records the failure,
  fires `JobFailed`, logs it, and skips `failed()` because the job is `null`.

`delete_when_missing_models()` is a protected worker method. It reads
`(new ReflectionClass($class))->getDefaultProperties()['delete_when_missing_models'] ?? false`,
and only an exact `true` counts. It reads the class's default property value because the job
instance couldn't be built. `Payload::restore()` has already confirmed that the class exists and
implements `ShouldQueue` before `unserialize()`, and so before the exception could be thrown.

**Alternative considered:** writing the flag into the envelope at encode time. Rejected because it
changes the envelope format, and a tampered row could turn failures into silent deletes.

`$delete_when_missing_models` is not declared on the trait. Following `Queueable`'s treatment of
`$tries`, a job that redeclared it with a different default would be a fatal error on PHP 7.4.

### 8. Stub and docs

`job.stub` adds `use Framework\Queue\Concerns\SerializesModels;` and `use SerializesModels;`. Its constructor comment changes from "pass IDs, not models" to explain
that models are stored as their identity and re-fetched when the job runs.

`docs/queues.md` changes:
- The "Pass IDs and scalars, not objects" advice becomes a SerializesModels section.
- In "Where this differs from Laravel", the "No `SerializesModels`" bullet is replaced by these
  differences:
  - no per-instance connection
  - `without_relations()` instead of `#[WithoutRelations]`
  - unsaved models are kept as they are
  - no pivot or morph restoration
  - `$delete_when_missing_models` is snake_case

## Risks / Trade-offs

- **Reading protected `Model::$exists` through reflection.** Renaming that property would silently
  make every model look unsaved, so it would be serialized as a snapshot. → A unit test asserts
  that a hydrated model becomes an identifier. The reflection lives in one cached helper.
- **One query per model property at restore, plus eager loads.** → This is inherent to the
  feature, and Laravel has the same cost. Collections use a single `where_in`.
- **Payloads already queued are not converted after a deploy.** A job gains the trait while a
  payload written by the old class is still queued. → `__unserialize()` receives a plain array
  whose values are not `ModelIdentifier`s, and it restores them as they are.
- **Job classes deployed with the trait removed.** → Mangled keys match PHP's native format.
  Without `__unserialize()`, however, the `ModelIdentifier` values would land on the properties
  as they are. This is documented: removing the trait while such payloads are queued requires
  draining the queue first.
- **A `LogicException` from a destructor.** → It propagates at the end of the dispatch statement.
  If it is triggered during script shutdown it is a fatal error, but dispatching during shutdown
  is already unsupported.
