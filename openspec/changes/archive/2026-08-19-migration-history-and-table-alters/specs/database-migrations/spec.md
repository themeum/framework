## Purpose

Tracks which migrations have been applied to a site's database so that repeated migration runs execute only pending work, and so applied migrations can be undone in the order and grouping they were applied.

## ADDED Requirements

### Requirement: Applied migrations are recorded

The system SHALL record every migration it successfully applies, together with the batch it belonged to and the time it ran. The record SHALL persist across requests and SHALL be scoped to the site.

#### Scenario: A migration is applied for the first time

- **WHEN** a migration that has no recorded history is run
- **THEN** its `up()` behavior is executed
- **AND** it is recorded as applied with a batch number and a timestamp

#### Scenario: History survives the request

- **WHEN** a migration run completes and a later request queries migration history
- **THEN** the previously applied migrations are still reported as applied

### Requirement: Only pending migrations run

The system SHALL execute only migrations that have no recorded history. Migrations already recorded as applied SHALL be skipped without executing their behavior.

#### Scenario: Running migrations twice

- **WHEN** the migration command is run a second time with no new migrations registered
- **THEN** no migration behavior is executed
- **AND** the database schema is unchanged

#### Scenario: A new migration is added to an existing installation

- **WHEN** a new migration is registered after previous ones have been applied and the migration command is run
- **THEN** only the new migration is executed
- **AND** the previously applied migrations are not re-executed

### Requirement: Migrations applied together share a batch

All migrations applied during a single migration run SHALL be recorded with the same batch number. Each subsequent run SHALL use a batch number greater than every batch already recorded.

#### Scenario: Two separate runs

- **WHEN** two migrations are applied in one run and a third is applied in a later run
- **THEN** the first two share one batch number
- **AND** the third has a higher batch number

### Requirement: History is written as each migration succeeds

The system SHALL record each migration as applied immediately after that migration succeeds, rather than after the whole run completes. When a migration fails, migrations already applied during that run SHALL remain recorded as applied.

#### Scenario: A migration fails partway through a run

- **WHEN** three migrations are pending and the third fails
- **THEN** the first two are recorded as applied
- **AND** the failure is surfaced to the caller

#### Scenario: Re-running after a partial failure

- **WHEN** the migration command is run again after a partial failure
- **THEN** the migrations that already succeeded are skipped
- **AND** execution resumes at the migration that failed

### Requirement: Legacy history is readable

The system SHALL interpret migration history recorded in the older format, which carried no batch or timestamp, as belonging to the first batch with an unknown timestamp.

#### Scenario: History recorded before batches existed

- **WHEN** migration history exists in the legacy format and history is read
- **THEN** each entry reports as applied in batch 1
- **AND** no error is raised

### Requirement: Applied migrations can be rolled back by batch

The system SHALL provide a rollback operation that undoes the migrations of the most recent batch. The operation SHALL accept a step count so that the most recent N batches can be undone in one invocation. Rolled-back migrations SHALL be removed from history so they are pending again.

#### Scenario: Rolling back the last batch

- **WHEN** rollback is invoked with no step count
- **THEN** only the migrations in the highest-numbered batch are undone
- **AND** migrations in earlier batches remain applied

#### Scenario: Rolling back multiple batches

- **WHEN** rollback is invoked with a step count of 2
- **THEN** the migrations in the two highest-numbered batches are undone

#### Scenario: Rolled-back migrations become pending

- **WHEN** a batch is rolled back and the migration command is run again
- **THEN** the rolled-back migrations are executed again

#### Scenario: Rolling back with no history

- **WHEN** rollback is invoked and no migrations are recorded as applied
- **THEN** nothing is undone
- **AND** no error is raised

### Requirement: Rollback undoes migrations in reverse order under relaxed constraints

Within a batch, the system SHALL undo migrations in the reverse of the order in which they are registered, and SHALL suspend foreign key constraint checking for the duration of the rollback, restoring it even when a migration fails.

#### Scenario: Rolling back tables with foreign key relationships

- **WHEN** a batch contains a parent table registered before a child table that references it
- **AND** that batch is rolled back
- **THEN** the child table is dropped before the parent table
- **AND** the rollback completes without a foreign key constraint error

#### Scenario: A rollback step fails

- **WHEN** undoing a migration raises an error
- **THEN** foreign key constraint checking is restored

### Requirement: Resetting the database undoes applied migrations and clears history

The fresh operation SHALL undo only the migrations recorded as applied, in the reverse of their registration order, and SHALL then clear the recorded migration history so that every migration is pending for the run that follows. Migrations with no recorded history SHALL NOT be undone, because an unapplied migration has nothing to reverse.

#### Scenario: Refreshing a database that has recorded history

- **WHEN** the fresh operation runs against a database whose migrations are all recorded as applied
- **THEN** every applied migration is undone
- **AND** the history is cleared
- **AND** every migration is re-applied
- **AND** the resulting database contains the full schema rather than no tables

#### Scenario: Refreshing when a registered migration was never applied

- **WHEN** the fresh operation runs and one registered migration has no recorded history
- **THEN** that migration is not undone
- **AND** the operation completes without error

#### Scenario: Undo order during a refresh

- **WHEN** the fresh operation undoes several applied migrations
- **THEN** they are undone in the reverse of their registration order

### Requirement: Migration status is reportable

The system SHALL expose the applied-or-pending state of every registered migration, in registration order, including the batch number of each applied migration.

#### Scenario: Inspecting a partially migrated database

- **WHEN** status is requested and some registered migrations have been applied
- **THEN** each applied migration is reported as applied with its batch number
- **AND** each unapplied migration is reported as pending with no batch number

#### Scenario: Inspecting before any migration has run

- **WHEN** status is requested and no migrations have been applied
- **THEN** every registered migration is reported as pending

### Requirement: Generated migrations are registered and can target existing tables

The migration generator SHALL support generating a migration that alters an existing table as well as one that creates a new table, and SHALL add every generated migration to the ordered registration list so it is picked up by the next migration run without manual editing.

#### Scenario: Generating a migration that alters a table

- **WHEN** a migration is generated for an existing table
- **THEN** the generated file is scaffolded to alter that table in both its apply and undo behavior

#### Scenario: Generating a migration that creates a table

- **WHEN** a migration is generated without naming an existing table to alter
- **THEN** the generated file is scaffolded to create a table and drop it on undo

#### Scenario: A generated migration is picked up

- **WHEN** a migration is generated and the migration command is run
- **THEN** the generated migration is executed without any manual registration step

#### Scenario: The registration list cannot be updated

- **WHEN** a migration is generated and the registration list is missing or cannot be parsed
- **THEN** the migration file is still written
- **AND** the caller is warned that manual registration is required
- **AND** the generator does not fail
