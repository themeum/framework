## Purpose

Lets a consuming plugin opt a cache store into starting cold whenever the application version
changes, so that entries written by an older release can never be read by code that expects a
different shape.

## Requirements

### Requirement: Version scoping is opt-in per store
Each configured cache store SHALL accept a boolean `version_scoped` setting, which defaults to
`false`. A store whose setting is anything other than `true` SHALL behave exactly as it did before
this capability existed. Nothing SHALL be flushed, and no version SHALL be recorded for it.

#### Scenario: Disabled store keeps values across a version change
- **WHEN** a store with `version_scoped => false` holds a value written while the application version was A, and the store is resolved again in a later request where the application version is B
- **THEN** the value is still returned

#### Scenario: Default configuration leaves scoping off
- **WHEN** the built-in `database` or `file` store is resolved with no `version_scoped` setting configured
- **THEN** the setting is `false` and no version is recorded

### Requirement: A version change empties a scoped store
When a store with `version_scoped => true` is first resolved in a request, the framework SHALL
compare the application version with the version recorded for that store. If they differ, the
framework SHALL flush the store before handing it out and SHALL then record the current
application version. Any difference SHALL count as a change, a downgrade included.

#### Scenario: Value written under an older version is gone
- **WHEN** a store with `version_scoped => true` holds a value written while the application version was A, and the store is resolved again in a later request where the application version is B
- **THEN** the value is not returned, and B is recorded as the store's version

#### Scenario: Same version does not flush
- **WHEN** a store with `version_scoped => true` is resolved while the recorded version equals the application version
- **THEN** the store is not flushed and its entries remain readable

#### Scenario: Application without a version
- **WHEN** a store with `version_scoped => true` is resolved and the application exposes no version
- **THEN** the store is not flushed and no version is recorded

### Requirement: A missing recorded version counts as a change
If no version has been recorded for a scoped store, the framework SHALL treat that as a version
change, flush the store, and record the current version. A site upgrading from a framework release
without this capability may hold entries in an old shape and MUST start cold.

#### Scenario: First resolve after upgrading the framework
- **WHEN** a store with `version_scoped => true` holds a value and has no recorded version
- **THEN** the first resolve flushes the store, the value is no longer returned, and the current application version is recorded

### Requirement: Check runs at most once per store per request
The version check SHALL run only when a store is resolved for the first time in a request, and
SHALL NOT run for stores the request never uses. Resolving the same store again in the same
request, including from inside a listener for the flush event, SHALL return the already-resolved
store without checking or flushing again.

#### Scenario: Re-entrant resolve during the flush
- **WHEN** a listener for the cache-flushed event resolves the same store while the version check is flushing it
- **THEN** the store is flushed exactly once in that request

### Requirement: Recorded version storage
The recorded version SHALL be stored in an autoloaded option named
`<application prefix>cache_app_version_<store name>`. For a store configured with `network => true`
it SHALL be a network-wide site option instead.

#### Scenario: Site-scoped store
- **WHEN** a store with `version_scoped => true` and `network => false` records a version
- **THEN** the version is written to the site option `<prefix>cache_app_version_<store name>` and no network option is written

#### Scenario: Network store
- **WHEN** a store with `version_scoped => true` and `network => true` records a version
- **THEN** the version is read from and written to the network option `<prefix>cache_app_version_<store name>`, and no site option is written

### Requirement: Failed flush is retried
The version SHALL be recorded only when the flush reports success. When the flush reports failure,
the recorded version SHALL remain unchanged, so the next request tries the flush again. An
exception raised by the flush SHALL reach the caller unchanged.

#### Scenario: Flush reports failure
- **WHEN** a scoped store's flush reports failure during a version change
- **THEN** the recorded version is not updated

### Requirement: Fallback stores are checked under their own name
When a store diverts to a fallback store, the version check SHALL use the fallback store's name
and configuration, not those of the store that diverted.

#### Scenario: File store falls back to the database store
- **WHEN** a `file` store with `version_scoped => true` cannot run and diverts to a `database` store with `version_scoped => false`
- **THEN** the database store is not flushed and no version is recorded under the `file` store's name

### Requirement: Flushed entries remain reclaimable
A flush triggered by a version change SHALL leave the superseded entries reclaimable by the
store's scheduled garbage collection, exactly as an explicit flush does.

#### Scenario: Database store queues the old namespace
- **WHEN** a scoped `database` store is flushed because the version changed
- **THEN** the superseded namespace version is in the store's garbage-collection queue, and a garbage-collection run removes its rows
