## ADDED Requirements

### Requirement: Atomic locks

The cache SHALL provide a named lock that at most one caller can hold at a time. Acquiring a
lock SHALL be atomic: when several callers attempt to acquire the same lock simultaneously,
exactly one SHALL succeed and the rest SHALL be told they did not acquire it.

Acquisition SHALL NOT block. A caller that does not acquire the lock SHALL be informed
immediately rather than waiting.

This supersedes the earlier decision to exclude locking from the cache. It is added because
a correct rate limiter cannot be built on the non-atomic counters described under
"Counters", which remain non-atomic and unchanged.

#### Scenario: Only one caller acquires a lock

- **WHEN** two callers attempt to acquire the same named lock simultaneously
- **THEN** exactly one is told it acquired the lock and the other is told it did not

#### Scenario: A released lock can be re-acquired

- **GIVEN** a caller holds a lock
- **WHEN** that caller releases it
- **THEN** another caller can acquire it

#### Scenario: Acquisition does not wait

- **GIVEN** a lock is already held
- **WHEN** another caller attempts to acquire it
- **THEN** the attempt returns immediately reporting failure, without waiting for the holder

### Requirement: Locks expire so a crash cannot deadlock

Every lock SHALL be acquired with an expiry. Once that expiry has elapsed the lock SHALL
become acquirable again, whether or not it was released, so that a request that dies while
holding a lock cannot block a key permanently.

Storage for expired locks SHALL be reclaimed by the cache's existing scheduled maintenance
rather than accumulating.

#### Scenario: An abandoned lock becomes available

- **GIVEN** a caller acquired a lock and never released it
- **WHEN** the lock's expiry has elapsed
- **THEN** another caller can acquire it

#### Scenario: Expired lock storage is reclaimed

- **WHEN** the cache's scheduled maintenance runs
- **THEN** storage held by expired locks is released

### Requirement: Only the holder releases a lock

A lock SHALL record which caller holds it. Releasing SHALL only take effect for the caller
that currently holds the lock, so that a caller whose lock has already expired and been
acquired by someone else cannot release the new holder's lock.

#### Scenario: A stale holder cannot release

- **GIVEN** a caller's lock expired and was acquired by a second caller
- **WHEN** the first caller releases its lock
- **THEN** the second caller still holds the lock

#### Scenario: The holder is identifiable

- **WHEN** a caller acquires a lock
- **THEN** it can read back an identifier distinguishing its hold from any other

### Requirement: Running work under a lock

The cache SHALL support acquiring a lock, running a callback, and releasing the lock
afterwards as one operation. The lock SHALL be released even when the callback raises.

When the lock is not acquired, the callback SHALL NOT run.

#### Scenario: The callback runs and the lock is released

- **WHEN** work is run under an available lock
- **THEN** the callback executes and the lock is released afterwards

#### Scenario: The lock is released after a failure

- **WHEN** a callback running under a lock raises
- **THEN** the lock is released and the failure propagates to the caller

#### Scenario: The callback is skipped when the lock is unavailable

- **GIVEN** a lock is already held
- **WHEN** work is requested under that lock
- **THEN** the callback does not run

### Requirement: Locks work without a persistent object cache

Locks SHALL be atomic on a default WordPress installation, not only where a persistent
object cache drop-in is present. Where a persistent object cache offers an atomic operation
the cache SHALL use it; otherwise the cache SHALL fall back to a storage mechanism that is
atomic in its own right.

#### Scenario: Atomic on a default installation

- **GIVEN** no persistent object cache is installed
- **WHEN** two callers attempt the same lock simultaneously
- **THEN** exactly one acquires it

#### Scenario: Atomic with a persistent object cache

- **GIVEN** a persistent object cache drop-in is installed
- **WHEN** two callers attempt the same lock simultaneously
- **THEN** exactly one acquires it, and behaviour is otherwise identical
