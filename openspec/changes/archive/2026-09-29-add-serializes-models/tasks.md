## 1. Model support

- [x] 1.1 Add `tests/Unit/Database/Model/ModelWithoutRelationsTest.php` covering the existing `HasRelationships::without_relations()`. The copy has the same attributes and no relations, and the original keeps its relations. `Model` itself needs no change.

## 2. Identifier and trait

- [x] 2.1 Create `src/Queue/ModelIdentifier.php` as a plain value class with public `class`, `id`, `relations` and `collection_class` properties and a constructor. File header, docblocks, `@since 3.2.1`.
- [x] 2.2 Create `src/Queue/Concerns/SerializesModels.php`. Add `__serialize()`, which walks the class and its parent classes, skips static and uninitialized typed properties, and uses PHP's mangled keys. Add `__unserialize()`, which demangles each key, finds the declaring property, and ignores unknown keys.
- [x] 2.3 In the trait, add `get_serialized_property_value()`:
  - A saved model, where reflected `Model::$exists` is true and the key is not null, becomes a `ModelIdentifier` holding its class, key and relations.
  - A `Database\Query\Collection` whose items are all saved models of one class becomes a `ModelIdentifier` holding the ordered keys and the collection class.
  - A collection of saved models of more than one class throws a `LogicException`.
  - Anything else is returned unchanged.
- [x] 2.4 In the trait, add `get_queueable_relations()`. It records dotted nested paths, takes the intersection across collection items, and records null relations by name.
- [x] 2.5 In the trait, add `get_restored_property_value()`, which validates the class before any query:
  - The class must be a `Model` subclass and the collection class must be a `Query\Collection`. Otherwise throw `QueueException::invalid_payload`.
  - A single model is restored through `query()->with()->where(key)->first()`. A null result throws `ModelNotFoundException`.
  - A collection is restored through one `where_in`, keeps the original order, and drops missing rows. An empty key list returns an empty collection without a query.
- [x] 2.6 Run `composer phpcs` and fix any violations in the new files.

## 3. Worker

- [x] 3.1 In `src/Queue/Worker.php`, catch `ModelNotFoundException` around `Payload::restore()`:
  - If the job class's default `$delete_when_missing_models` is exactly `true`, delete the record and return.
  - Otherwise call `fail_job($record, null, $e)`.
- [x] 3.2 Add the protected `delete_when_missing_models(string $class)` helper, which reads `ReflectionClass::getDefaultProperties()`, with a docblock and `@since 3.2.1`.

## 4. Stub

- [x] 4.1 Update `src/Console/stubs/job.stub` to import and use `Framework\Queue\Concerns\SerializesModels`, and replace the "pass IDs rather than models" constructor comment with guidance on how model serialization works.
- [x] 4.2 Update the stub assertion in `tests/Unit/Queue/QueueCommandsTest.php`, if one exists, so it expects the trait.

## 5. Test fixtures

- [x] 5.1 Add stub models under `tests/Support/Models/`: an article that has many comments, and a comment that belongs to an author. Include the table names used by `ModelTestWpdb` seeding.
- [x] 5.2 Add jobs under `tests/Support/Queue/Jobs/`:
  - a job using the trait with private, protected and public model properties
  - a job holding a collection
  - a job holding a model nested in an array
  - a job with an uninitialized typed property
  - a job with `$delete_when_missing_models = true` and three tries
  - a job without the trait, as a control

## 6. Tests

- [x] 6.1 Add `tests/Unit/Queue/SerializesModelsTest.php` for serialization:
  - the payload excludes attribute values
  - a model is restored fresh after its row changes
  - a control job without the trait stays frozen
  - other property types round-trip unchanged
  - private, protected and public properties are all handled
  - an uninitialized typed property is tolerated
  - a model nested in an array stays a snapshot
  - unsaved models, and collections containing one, stay as they are
- [x] 6.2 Add relation tests:
  - top-level relations are re-loaded with fresh rows
  - nested dotted relations are re-loaded
  - no relations are loaded when none were loaded at dispatch
  - `without_relations()` restores the model with no relations
- [x] 6.3 Add collection tests:
  - order is preserved
  - deleted rows are dropped and the job still runs
  - an empty collection restores empty with the same class
  - a mixed-class collection throws at dispatch and stores no row
- [x] 6.4 Add worker tests:
  - a missing model fails the job on its first of three attempts, with a `ModelNotFoundException` naming the class and key, a `JobFailed` event, a log entry, and no `failed()` call
  - with `$delete_when_missing_models = true`, the job is deleted with no failed row and no event
  - the next job in the batch still runs
  - a tampered model class fails the job as an invalid payload without instantiating that class
- [x] 6.5 Run `composer phpcs && composer test:unit` and make both pass.

## 7. Documentation

- [x] 7.1 In `docs/queues.md`:
  - Replace the "Pass IDs and scalars, not objects" advice with a SerializesModels section covering opt-in, what is converted, relations and `without_relations()`, collections, unsaved models, missing models and `$delete_when_missing_models`, and draining the queue before removing the trait.
  - Add the section to the table of contents.
- [x] 7.2 In the "Where this differs from Laravel" section of `docs/queues.md`, replace the "No `SerializesModels`" bullet with these differences:
  - no per-instance connection
  - `without_relations()` instead of `#[WithoutRelations]`
  - unsaved models are kept as they are
  - no pivot or morph restoration
  - snake_case `$delete_when_missing_models`
