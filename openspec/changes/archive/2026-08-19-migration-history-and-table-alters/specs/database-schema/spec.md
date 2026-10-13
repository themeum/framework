## Purpose

Lets migrations evolve tables that already exist in a production database — adding, changing, renaming, and removing columns, indexes, and constraints — rather than only creating and dropping whole tables.

## ADDED Requirements

### Requirement: Existing tables can be altered

The schema builder SHALL expose a table alteration entry point that accepts a table name and a callback describing the changes, mirroring the existing table creation entry point. The callback SHALL receive the same builder type used for table creation, so migrations need only one type reference.

#### Scenario: Altering a table

- **WHEN** the alteration entry point is invoked with a table name and a callback that describes changes
- **THEN** those changes are applied to the existing table

#### Scenario: A callback that describes no changes

- **WHEN** the alteration entry point is invoked with a callback that records no operations
- **THEN** an error is raised rather than an invalid statement being issued

#### Scenario: Altering a table that does not exist

- **WHEN** the alteration entry point targets a table that does not exist
- **THEN** the failure is surfaced to the caller as an error

### Requirement: All changes in one callback are applied together

All operations recorded by a single alteration callback SHALL be applied as one atomic table alteration. If any operation in the callback is invalid, none of the operations SHALL take effect.

#### Scenario: One callback with several operations

- **WHEN** a callback adds a column, changes another, and drops a third
- **THEN** all three changes are applied in a single alteration of the table

#### Scenario: One operation in the callback is invalid

- **WHEN** a callback records four operations and the third is invalid
- **THEN** none of the four operations take effect
- **AND** the table is left in its original state

### Requirement: Columns can be added to an existing table

The builder SHALL support adding columns to an existing table using the same column type and modifier vocabulary available when creating a table.

#### Scenario: Adding a column

- **WHEN** a column is declared in an alteration callback without being marked as a change
- **THEN** the column is added to the table with the declared type and modifiers

#### Scenario: Adding a column that already exists

- **WHEN** a column is added whose name is already present on the table
- **THEN** an error is raised rather than the operation being silently skipped

### Requirement: Added columns can be positioned

The builder SHALL support placing a newly added column immediately after a named existing column, or first in the table. Positioning SHALL apply only when altering a table and SHALL never appear in a table creation statement.

#### Scenario: Adding a column after a named column

- **WHEN** a column is added and declared to follow a named existing column
- **THEN** the new column is positioned immediately after that column

#### Scenario: Adding a column at the start

- **WHEN** a column is added and declared to be first
- **THEN** the new column is positioned before all other columns

#### Scenario: Positioning is declared during table creation

- **WHEN** a positioning modifier is declared while creating a new table
- **THEN** the positioning is ignored
- **AND** the table is created successfully

### Requirement: Existing columns can be changed

The builder SHALL support changing an existing column's type and modifiers. A changed column SHALL be declared with its complete intended definition; modifiers not restated SHALL NOT be carried over from the column's current definition.

#### Scenario: Widening a column

- **WHEN** an existing column is redeclared with a larger length and marked as a change
- **THEN** the column's type is updated to the new length
- **AND** the column is not duplicated

#### Scenario: Modifiers are not inherited

- **WHEN** a column that is currently nullable is redeclared as a change without restating nullability
- **THEN** the resulting column takes the default nullability of a newly declared column rather than its previous nullability

### Requirement: Columns can be renamed across supported database versions

The builder SHALL support renaming a column while preserving its existing type, nullability, default, comment, and auto-increment behavior, without requiring the caller to restate them. Renaming SHALL work on every database version the framework supports, not only those offering a dedicated rename syntax.

#### Scenario: Renaming a column

- **WHEN** a column is renamed
- **THEN** the column is available under its new name
- **AND** its type, nullability, default, and comment are unchanged
- **AND** the data it held is preserved

#### Scenario: Renaming on an older database server

- **WHEN** a column is renamed on a database server that does not support dedicated rename syntax
- **THEN** the rename still succeeds

#### Scenario: Renaming a column that does not exist

- **WHEN** a rename targets a column that is not present on the table
- **THEN** an error identifying the missing column is raised

### Requirement: Columns can be dropped

The builder SHALL support dropping one or more columns from an existing table.

#### Scenario: Dropping a single column

- **WHEN** a column is dropped by name
- **THEN** the column is removed from the table

#### Scenario: Dropping several columns

- **WHEN** several column names are dropped in one callback
- **THEN** all of them are removed in the same alteration

#### Scenario: Dropping a column that does not exist

- **WHEN** a drop targets a column that is not present on the table
- **THEN** an error is raised rather than the operation being silently skipped

### Requirement: Indexes and keys can be added and dropped on an existing table

The builder SHALL support adding and dropping indexes, unique keys, and the primary key on an existing table. Index and unique key names SHALL be derivable from the table and column names using the same naming convention applied at table creation, so a caller may drop them by column instead of by name.

#### Scenario: Adding an index

- **WHEN** an index is declared over one or more columns in an alteration callback
- **THEN** the index is created on the table

#### Scenario: Dropping an index by column

- **WHEN** an index is dropped by naming the columns it covers
- **THEN** the index created under the conventional name for those columns is removed

#### Scenario: Dropping an index by name

- **WHEN** an index is dropped by its explicit name
- **THEN** that named index is removed

#### Scenario: Dropping the primary key

- **WHEN** the primary key is dropped
- **THEN** the table no longer has a primary key

### Requirement: Foreign key constraints are named predictably

Foreign key constraints SHALL be created with an explicit name derived from the table and referencing column, both when a table is created and when a constraint is added to an existing table, unless the caller supplies a name. Constraints SHALL be droppable by that derived name or by naming the columns they cover.

#### Scenario: Creating a table with a foreign key

- **WHEN** a table is created with a foreign key on a column
- **THEN** the constraint carries the conventional name derived from the table and column rather than a server-assigned name

#### Scenario: Adding a foreign key to an existing table

- **WHEN** a foreign key is added in an alteration callback
- **THEN** the constraint carries the same conventional name it would have received at table creation

#### Scenario: Dropping a foreign key by column

- **WHEN** a foreign key is dropped by naming the column it covers
- **THEN** the constraint under the conventional name for that column is removed

#### Scenario: Dropping a foreign key created before this convention existed

- **WHEN** a foreign key that carries a server-assigned name is dropped by its explicit name
- **THEN** that constraint is removed

#### Scenario: A caller supplies a constraint name

- **WHEN** a foreign key is declared with an explicit name
- **THEN** the constraint carries that name instead of the derived one

### Requirement: Tables can be renamed

The schema builder SHALL support renaming an existing table.

#### Scenario: Renaming a table

- **WHEN** a table is renamed
- **THEN** the table is available under its new name with its data and structure preserved

### Requirement: Schema presence can be inspected

The schema builder SHALL expose read-only checks for whether a table exists, whether a named column exists on a table, and whether every one of a set of named columns exists on a table.

#### Scenario: Checking an existing table

- **WHEN** a table's existence is checked and the table is present
- **THEN** the check reports true

#### Scenario: Checking a missing column

- **WHEN** a column's existence is checked and the column is absent
- **THEN** the check reports false

#### Scenario: Checking a set of columns where one is missing

- **WHEN** several columns are checked together and one of them is absent
- **THEN** the check reports false

### Requirement: Alteration operations do not silently skip work

Alteration operations SHALL NOT guard themselves against pre-existing or missing schema objects. An operation that cannot be applied SHALL surface an error so that duplicate runs and mistyped names are visible rather than absorbed.

#### Scenario: An alteration is applied twice

- **WHEN** an alteration that adds a column is applied to a table that already has that column
- **THEN** an error is raised

#### Scenario: An operation names a nonexistent object

- **WHEN** an alteration drops a column whose name is misspelled
- **THEN** an error is raised rather than the operation completing as a no-op
