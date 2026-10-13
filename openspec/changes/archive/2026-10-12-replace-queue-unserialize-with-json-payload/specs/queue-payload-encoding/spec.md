## Purpose

Defines how a queued job is stored in and restored from the jobs table without using PHP object
deserialization, so a tampered row cannot instantiate arbitrary classes.

## ADDED Requirements

### Requirement: Jobs are stored as JSON
A dispatched job SHALL be stored as a JSON document that records the job class, its display name,
try and backoff settings, priority, and the job's property values. The
stored payload MUST NOT contain PHP-serialized data, and the library MUST NOT call PHP
deserialization functions when restoring a job.

#### Scenario: Round trip
- **WHEN** a job with integer, string, float, boolean, null and array properties is dispatched and processed
- **THEN** the processed job holds equal values of the same types, including nested arrays and their keys

#### Scenario: Runtime state is not stored
- **WHEN** a job that has a reserved job record attached is dispatched
- **THEN** the stored payload contains no trace of the record and the restored job starts with no record

#### Scenario: Values that JSON cannot carry directly
- **WHEN** a job holds a binary (non-UTF-8) string, a non-finite float, or an array whose keys collide with the codec's reserved markers
- **THEN** the value is restored identically rather than altered or dropped

### Requirement: Only trusted classes are restored
Restoring a job SHALL instantiate only the class named by the envelope, and only if it exists and
implements the queued-job contract. Nested objects SHALL be restored only when their class is a
built-in supported type or is declared by the job as allowed. A stored object of any other class
MUST cause the job to fail without running, and no object of that class MUST be instantiated.

#### Scenario: Tampered job class
- **WHEN** a stored row names a class that does not exist or is not a queued job
- **THEN** the job is not run and is moved to the failed jobs table with an explanatory exception

#### Scenario: Tampered nested class
- **WHEN** a stored row's property tree names a class that is neither built in nor declared by the job
- **THEN** that class is never instantiated, its magic methods never run, and the job is failed with an explanatory exception

#### Scenario: Declared value object
- **WHEN** a job declares a value-object class as allowed and holds an instance of it
- **THEN** the instance is restored with the same class and property values

#### Scenario: Built-in date types
- **WHEN** a job holds a `DateTime` or `DateTimeImmutable`
- **THEN** it is restored as the same class, the same instant and the same time zone

### Requirement: Unsupported objects fail at dispatch
Dispatching a job that holds an object the codec cannot store (a class that is not allowed, a
closure, a resource) SHALL throw an exception naming the job class, the property and the offending
type. Nothing MUST be written to the jobs table.

#### Scenario: WordPress object on a job
- **WHEN** a job holding a `WP_Post` is dispatched
- **THEN** dispatch throws an exception that names the property and suggests storing the ID, and no row is created

### Requirement: Malformed property data is rejected
A stored property tree that is structurally invalid, or whose values cannot be assigned to the
job's properties (for example a type mismatch on a typed property), SHALL fail the job with an
explanatory exception rather than raising an uncaught error in the worker.

#### Scenario: Type mismatch
- **WHEN** a stored row sets a typed integer property to an array
- **THEN** the job is failed with an invalid-payload exception and the worker continues with the next job
