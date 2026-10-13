## Why

The migrator has never recorded what it ran. `Migrator::run()` builds up a `$previous_migrations` map and then throws it away — the line that would persist it is commented out and has been since the file was created, so every `wp kirki migrate` re-executes every registered migration. This has been survivable only by accident: every migration today is `Schema::create()`, which compiles to `CREATE TABLE IF NOT EXISTS`, so re-running is a harmless no-op.

That accident is also the ceiling. There is no `Schema::table()`, so a plugin that has already shipped a table has no supported way to add a column, change a type, rename, or drop an index — the schema builder can only create and drop whole tables. The moment ALTER exists, re-running stops being idempotent (`ADD COLUMN` twice throws), which means history tracking is a **prerequisite** for ALTER rather than a parallel nicety. Both ship together, history first.

## What Changes

**Migration history**

- `Migrator::run()` persists each migration as `['batch' => N, 'ran_at' => 'Y-m-d H:i:s']` **write-through** — immediately after each successful `up()`, so a failure on migration 3 leaves 1 and 2 correctly recorded and the next run resumes at 3
- All migrations in a single `migrate` invocation share one batch number
- `MigrationRepository` gains batch-aware reads and writes: `record_migration()`, `forget_migration()`, `get_next_batch_number()`, `get_last_batch_number()`, `get_migrations_in_batch()`, `get_batch_numbers()`. A read-shim normalizes the legacy `[FQCN => true]` shape to `['batch' => 1, 'ran_at' => null]`
- New `wp kirki migrate:status` — reports every registered migration as ran/pending with its batch
- New `wp kirki migrate:rollback [--step=N]` — wires up the already-written but unreachable `Migrator::rollback()`
- **BREAKING (behavioural)** — `wp kirki migrate` is now a no-op on a database whose migrations have all run. This is the intended fix, but it is a change in observable behaviour for anyone who was relying on repeated runs to re-assert schema
- **Bug fix** — `Migrator::fresh()` now clears the history option. Without this, enabling persistence would make `migrate:fresh` drop every table and then skip every migration as "already ran", leaving an empty database
- **Bug fix** — `Migrator::rollback()` now iterates reverse registration order inside a foreign-key-check guard. It currently runs forward with no guard, which drops a parent table before its child

**Schema alteration**

- New `Schema::table(string $table, Closure $callback)` — the `ALTER TABLE` counterpart to `Schema::create()`, taking the same `Structure` type-hint so migrations keep one import
- `Structure` gains an alter mode and its verbs: `drop_column()`, `rename_column()`, `drop_index()`, `drop_unique()`, `drop_primary()`, `drop_foreign()`. Column modifiers `->change()`, `->after()`, and `->first()` come free through the existing `Fluent` magic
- One closure compiles to **one** combined `ALTER TABLE` with comma-joined clauses — MySQL DDL is not transactional, so a single statement is the only way to get all-or-nothing behaviour, and it rebuilds the table once instead of N times
- `rename_column()` reads the column's live definition from `information_schema` and emits `CHANGE COLUMN`, which works on MySQL 5.7/8 and every MariaDB. `RENAME COLUMN` is deliberately not used: it needs MySQL 8.0.1+/MariaDB 10.5.2+, and version-sniffing is a trap because MariaDB 10.4 passes a naive `>= 8.0` check while lacking the syntax
- New `Schema::rename()`, `Schema::has_table()`, `Schema::has_column()`, `Schema::has_columns()`
- Alter operations are **strict** — adding an existing column or dropping a missing one throws rather than silently skipping, so typos and duplicate runs surface as errors
- **BREAKING (schema output)** — foreign keys are now emitted as named `CONSTRAINT {table}_{column}_foreign` in both the create and alter paths. Previously they were unnamed, so MySQL auto-assigned `_ibfk_N`. Tables created before this change keep their `_ibfk_N` names and must be dropped by explicit name; newly created tables get the predictable name that `drop_foreign(['column'])` re-derives
- `make:migration --table=X` emits an alter stub, and the generator now appends the generated class to `config/migrations.php` — previously a generated migration was invisible to the migrator until hand-registered

## Capabilities

### New Capabilities

- `database-migrations`: Recording which migrations have run, batch grouping, write-through persistence, rollback by batch, status reporting, fresh-reset semantics, and migration authoring/registration through `make:migration`
- `database-schema`: Altering existing tables through `Schema::table()` — adding, modifying, renaming, and dropping columns; adding and dropping indexes, primary keys, and foreign keys; table renaming; foreign key constraint naming; and schema introspection helpers

### Modified Capabilities

<!-- none -->

## Impact

- `src/Database/Migrations/MigrationRepository.php` — batch-aware reads/writes, legacy read-shim
- `src/Database/Migrations/Migrator.php` — write-through persistence in `run()`; batch-scoped reverse-order `rollback()` with FK guard; `fresh()` clears history; new `status()`
- `src/Database/Schema/SchemaManager.php` — `table()`, `rename()`, `has_table()`, `has_column()`, `has_columns()`
- `src/Database/Schema/Structure.php` — create/alter mode, alter verbs, compile branch
- `src/Database/Schema/Compiler.php` — `compile_alter()`, alter-shaped command generators, `position` modifier, named FK constraints, `information_schema` column introspection
- `src/Supports/Facades/Schema.php` — `@method` docblock entries for the new surface
- `src/Console/Commands/RollbackCommand.php`, `StatusCommand.php` — new commands
- `src/Console/Commands/MakeMigrationCommand.php`, `src/Console/stubs/migration-table.stub` — alter stub and config auto-registration
- `src/Application.php` — register `migrate:rollback` and `migrate:status`
- `tests/Unit/Database/Schema/CompilerTest.php`, `tests/Unit/Database/Migrations/MigratorTest.php`, `tests/Support/Database/TestWpdb.php` — new coverage and test-double support
- `example/database/migrations/`, `example/config/migrations.php` — a real alter migration exercised against MySQL
- `docs/migrations.md` (new), `README.md` — documentation
- No new dependencies. PHP 7.4 floor unchanged. The only behavioural breaks are the two marked **BREAKING** above
