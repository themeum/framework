## Purpose

Lets a queued job hold framework models and model collections on its properties without freezing
them. They are stored as their identity when the job is dispatched and are re-fetched from the
database when the job runs, matching Laravel's `SerializesModels`.

## ADDED Requirements

### Requirement: Opt-in model serialization
Model serialization SHALL apply only to jobs that opt in through the model-serialization trait. A
job that does not opt in MUST be stored and restored exactly as before, with models frozen as
they were at dispatch. Opting in MUST NOT change how any other property value is stored.

#### Scenario: Job without the trait
- **WHEN** a job that does not use the trait holds a saved model and is dispatched and processed
- **THEN** the processed job's model has the attribute values it had at dispatch, even if the row changed in between

#### Scenario: Job with the trait
- **WHEN** a job that uses the trait holds a saved model, the model's row is updated after dispatch, and the job is processed
- **THEN** the processed job's model has the updated attribute values

#### Scenario: Other properties are untouched
- **WHEN** a job that uses the trait also holds an integer, a string, an array and a plain object
- **THEN** those values are restored exactly as they were at dispatch

### Requirement: Saved models are stored as their identity
For a job that uses the trait, each property whose value is a saved model SHALL be stored as the
model's class, its primary key value, and the relations loaded on it, instead of its attributes.
Only direct property values SHALL be converted: a model nested inside an array or another object
MUST be stored as it is. Static properties and typed properties that were never initialized MUST
be ignored. Properties of every visibility, including those declared by a parent class, SHALL be
handled.

#### Scenario: Payload excludes attributes
- **WHEN** a job that uses the trait holds a saved model whose attributes include a long text column, and the job is dispatched
- **THEN** the stored payload contains the model's class and key but not the text column's value

#### Scenario: Model restored fresh by key
- **WHEN** the job is processed
- **THEN** the property holds an instance of the same model class, loaded from the database by its key, and marked as existing

#### Scenario: Model nested in an array
- **WHEN** a job that uses the trait holds an array containing a saved model
- **THEN** the model inside the array is restored with the attribute values it had at dispatch

#### Scenario: Private and protected properties
- **WHEN** a job that uses the trait holds saved models on a private, a protected and a public property
- **THEN** all three are restored fresh on their original properties

#### Scenario: Uninitialized typed property
- **WHEN** a job that uses the trait has a typed property that was never assigned
- **THEN** dispatching and processing the job succeed and the property is still uninitialized

### Requirement: Loaded relations are re-loaded
When a model is stored as its identity, the relations loaded on it at dispatch SHALL be recorded,
including nested relations as dotted paths. On restore, the same relations SHALL be eager-loaded
fresh from the database. Relations not loaded at dispatch MUST NOT be loaded on restore.

#### Scenario: Top-level relation
- **WHEN** a job that uses the trait holds an article with its `comments` relation loaded, a new comment is added after dispatch, and the job is processed
- **THEN** the restored article has `comments` loaded, and it includes the new comment

#### Scenario: Nested relation
- **WHEN** the article was dispatched with `comments` loaded and each comment had its `author` loaded
- **THEN** the restored article has `comments` loaded and each restored comment has `author` loaded

#### Scenario: No relations loaded
- **WHEN** the article was dispatched with no relations loaded
- **THEN** the restored article has no relations loaded

### Requirement: Opting a model out of relation re-loading
A model SHALL provide a method that returns a copy of itself with no loaded relations, leaving
the original's relations intact. A job that stores that copy MUST restore the model without
relations.

#### Scenario: Copy without relations
- **WHEN** `without_relations()` is called on a model that has `comments` loaded
- **THEN** the returned model has the same attributes and no loaded relations, and the original still has `comments` loaded

#### Scenario: Job stores the copy
- **WHEN** a job that uses the trait assigns `$article->without_relations()` to a property and is processed
- **THEN** the restored article has no relations loaded

### Requirement: Model collections are stored as their keys
For a job that uses the trait, each property whose value is a model collection SHALL be stored as
the model class, the ordered list of keys, the loaded relations, and the collection class. On
restore, the models SHALL be fetched in a single query, returned in the original order, and held
in a collection of the original collection class. Rows that no longer exist MUST be dropped
silently. An empty collection SHALL restore as an empty collection of the same class. A collection
holding models of more than one class MUST be rejected when the job is dispatched.

#### Scenario: Order preserved
- **WHEN** a job that uses the trait holds a collection of models with keys 3, 1, 2 and is processed
- **THEN** the restored collection holds fresh models with keys 3, 1, 2 in that order

#### Scenario: Deleted rows dropped
- **WHEN** the row with key 1 is deleted after dispatch
- **THEN** the restored collection holds the models with keys 3 and 2, and the job runs

#### Scenario: Empty collection
- **WHEN** a job that uses the trait holds an empty model collection
- **THEN** the property is restored as an empty collection of the same class

#### Scenario: Mixed model classes
- **WHEN** a job that uses the trait holds a collection containing an article and a comment, and is dispatched
- **THEN** dispatching throws an exception saying collections with multiple model types cannot be queued, and no row is stored

### Requirement: Unsaved models are stored as they are
A model that has not been saved (not marked as existing, or without a primary key value) SHALL be
stored with its attributes, as if the job did not use the trait. A collection containing any
unsaved model SHALL be stored as it is.

#### Scenario: Unsaved model
- **WHEN** a job that uses the trait holds a model built with `new` and never saved, and is processed
- **THEN** the restored property is an unsaved model with the attributes it had at dispatch

#### Scenario: Collection with an unsaved model
- **WHEN** a job that uses the trait holds a collection of one saved model and one unsaved model
- **THEN** the restored collection holds both models with the attribute values they had at dispatch

### Requirement: Missing models fail or delete the job
When a job that uses the trait is processed and a model stored as its identity no longer exists,
the job MUST NOT run. By default the job SHALL be moved to the failed jobs table on that attempt,
regardless of its remaining tries. The failure SHALL record a model-not-found exception naming the
model class and key, and SHALL fire the job-failed event and be logged. The job's `failed()`
method MUST NOT be called, because the job could not be restored. If the job class declares a
`$delete_when_missing_models` property whose default value is `true`, the job SHALL instead be
removed from the queue without a failure record, failed event or log entry. In both cases the
worker MUST continue with the next job.

#### Scenario: Missing model fails the job
- **WHEN** a job with three tries holds an article whose row is deleted before the job is processed
- **THEN** on its first attempt the job is not run, is written to the failed jobs table with a model-not-found exception naming the article class and key, and is removed from the jobs table

#### Scenario: Missing model deletes the job
- **WHEN** a job whose class declares `public $delete_when_missing_models = true;` holds an article whose row is deleted before the job is processed
- **THEN** the job is removed from the jobs table, is not run, and no failed-jobs row is written

#### Scenario: Worker continues
- **WHEN** a batch holds a job with a missing model followed by an ordinary job
- **THEN** the ordinary job is processed in the same run

### Requirement: Stored model class is validated
When restoring a stored model identity, the recorded class MUST be checked to be a framework model
class before any query is run. An identity naming a class that does not exist or is not a model
SHALL cause the job to fail as an invalid payload, without instantiating the class.

#### Scenario: Tampered model class
- **WHEN** a stored job payload is edited so its model identity names a class that is not a model
- **THEN** the job is not run, the named class is not instantiated, and the job is moved to the failed jobs table with an invalid-payload exception
