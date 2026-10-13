## 1. Migration history storage

- [x] 1.1 Add a normalizing read to `MigrationRepository::get_previous_migrations()` so entries stored as the legacy `true` value are returned as `['batch' => 1, 'ran_at' => null]`, and entries already in the new shape pass through unchanged
- [x] 1.2 Add `MigrationRepository::record_migration(string $class, int $batch)` — reads history, merges one entry carrying the batch and a current timestamp, writes it back through the `Option` facade
- [x] 1.3 Add `MigrationRepository::forget_migration(string $class)` — removes one entry, delegating to `remove_migrations()` when the result is empty
- [x] 1.4 Add `MigrationRepository::get_next_batch_number()` (highest recorded batch + 1, or 1 when empty) and `get_last_batch_number()` (highest recorded batch, or 0 when empty)
- [x] 1.5 Add `MigrationRepository::get_batch_numbers()` (descending, unique) and `get_migrations_in_batch(int $batch)` to support step-scoped rollback
- [x] 1.6 Confirm every read and write still routes through the `Option` facade — no direct `get_option`/`update_option` calls

## 2. Migrator history semantics

- [x] 2.1 In `Migrator::run()`, resolve the batch number once before the loop, and after each successful `up()` call `record_migration()` immediately; delete the commented-out `update_migrations()` line
- [x] 2.2 Verify `run()` retains its existing `class_exists` and `instanceof Migration` validation, and that a migration already present in history is skipped without executing
- [x] 2.3 Rewrite `Migrator::rollback()` to accept a step count (default 1), resolve the target batches from `get_batch_numbers()`, and undo only migrations belonging to those batches
- [x] 2.4 Make `rollback()` iterate registered migrations in **reverse** order and call `forget_migration()` after each successful `down()`
- [x] 2.5 Wrap `rollback()` in a `try`/`finally` that disables foreign key checks and restores them, mirroring `fresh()`
- [x] 2.6 Add `remove_migrations()` to the `finally` block in `Migrator::fresh()` so a refresh leaves every migration pending
- [x] 2.7 Add `Migrator::status()` returning one row per registered migration in registration order, each carrying the short class name, whether it ran, and its batch number or null

## 3. Migration CLI commands

- [x] 3.1 Add `src/Console/Commands/RollbackCommand.php` extending `CommandBase`, with a `prepare()` declaring an optional `step` assoc argument, invoking `migrator()->rollback()` and reporting what was undone
- [x] 3.2 Add `src/Console/Commands/StatusCommand.php` extending `CommandBase`, rendering `migrator()->status()` through `\WP_CLI\Utils\format_items()` with Ran / Migration / Batch columns
- [x] 3.3 Register `migrate:rollback` and `migrate:status` in `Application::register_base_cli_commands()`

## 4. Part A verification gate

- [x] 4.1 Add `tests/Unit/Database/Migrations/MigratorTest.php` with a recording `Option` fake and migration doubles, following the double style in `tests/Support/Session/RecordingSessionHandler.php`
- [x] 4.2 Cover: a first run records every migration in one shared batch; a second run executes nothing
- [x] 4.3 Cover: a migration added later records in a strictly higher batch
- [x] 4.4 Cover: when the third of three migrations throws, the first two remain recorded and a re-run resumes at the third
- [x] 4.5 Cover: rollback undoes only the highest batch, in reverse registration order, and leaves earlier batches applied; `--step=2` spans two batches; rollback with empty history is a no-op
- [x] 4.6 Cover: `fresh()` clears history, and the legacy `[class => true]` shape reads back as batch 1
- [x] 4.7 Run `composer run phpcs && composer run test:unit` — Part A must be green before starting section 5

## 5. Structure alter mode

- [x] 5.1 Add `MODE_CREATE` and `MODE_ALTER` constants, a `$mode` property, and an **optional** third constructor parameter defaulting to create mode, so existing two-argument callers and `TestCase::make_structure()` keep working
- [x] 5.2 Add a public `is_altering()` accessor
- [x] 5.3 Add `drop_column($columns)` accepting a string or array, recording into `$commands`
- [x] 5.4 Add `rename_column(string $from, string $to)`
- [x] 5.5 Add `drop_index($keys_or_name)` and `drop_unique($keys_or_name)`, re-deriving `{table}_{cols}_index` / `{table}_{cols}_unique` from an array argument to match the names generated at creation time, and using a string argument verbatim
- [x] 5.6 Add `drop_primary()` and `drop_foreign($columns_or_name)`, the latter re-deriving `{table}_{column}_foreign` from an array
- [x] 5.7 Make every alter-only verb throw a clear exception when `!is_altering()`
- [x] 5.8 Branch `get_table_structure()` to `compile_alter()` when altering and `compile_create()` otherwise

## 6. Compiler ALTER support

- [x] 6.1 Add a `CONSTRAINT` name to `compile_foreign()`, defaulting to `{unprefixed_table}_{column}_foreign` and honouring an explicit name passed to `Structure::foreign()`
- [x] 6.2 Append `'position'` as the last entry of `$modifiers` and add `apply_position($column, $structure)` reading the `after` and `first` attributes, emitting only when `$structure->is_altering()`
- [x] 6.3 Add `Compiler::compile_alter(Structure $structure)` that calls `extract_commands_from_columns()` exactly once, partitions `get_columns()` on the `change` attribute, and reuses `compile_column()` unmodified for both `ADD COLUMN` and `MODIFY COLUMN` fragments
- [x] 6.4 Add ALTER-shaped command generators for adding a primary key, unique key, index, and foreign key — separate from the CREATE-body generators, which emit leading spaces and the `KEY` keyword
- [x] 6.5 Add clause generators for `DROP COLUMN`, `DROP INDEX`, `DROP PRIMARY KEY`, and `DROP FOREIGN KEY`
- [x] 6.6 Add `get_existing_column_definition($table, $column)` reusing `compile_database_columns()`, prefixing the table name before the lookup, rebuilding type / nullability / default / auto-increment / comment, emitting a `CURRENT_TIMESTAMP` default raw rather than quoted, and throwing a clear exception when the column is absent
- [x] 6.7 Emit renames as `CHANGE COLUMN` using that rebuilt definition
- [x] 6.8 Join all clauses with commas into a single `ALTER TABLE` statement, throw when the clause list is empty, and do not call `compile_create_engine()` or `compile_create_encoding()`

## 7. Schema manager and facade

- [x] 7.1 Add `SchemaManager::table(string $table, Closure $callback)` mirroring `create()` — build a `Structure` in alter mode, invoke the callback, run one query, inspect `$wpdb->last_error`, throw on failure
- [x] 7.2 Add `SchemaManager::rename(string $from, string $to)` emitting `RENAME TABLE` with both names wrapped through the query compiler
- [x] 7.3 Add `has_table()`, `has_column()`, and `has_columns()` built on the existing `get_column_listing()`
- [x] 7.4 Add `@method` docblock entries for `table`, `rename`, `has_table`, `has_column`, and `has_columns` to the `Schema` facade

## 8. Migration generator

- [x] 8.1 Add `src/Console/stubs/migration-table.stub` scaffolding `Schema::table()` in both `up()` and `down()`
- [x] 8.2 Add a `--table=X` assoc argument to `MakeMigrationCommand` selecting the alter stub; a bare name or `--create=X` keeps the existing create stub
- [x] 8.3 Append the generated fully-qualified class name to the array returned by `config/migrations.php`, warning and continuing (never failing) when that file is missing or unparseable

## 9. Schema test coverage

- [x] 9.1 Add `tests/Unit/Database/Schema/CompilerTest.php` using the existing `TestCase::make_structure()` seam, asserting exact SQL strings in the style of `tests/Unit/Database/Query/QueryCompilerTest.php`
- [x] 9.2 Add a CREATE-path regression test asserting the new named `CONSTRAINT` foreign key output
- [x] 9.3 Assert that a positioning modifier never appears in `CREATE TABLE` output
- [x] 9.4 Cover ALTER compilation: several operations joined into one statement, `ADD COLUMN` versus `MODIFY COLUMN` partitioning, `AFTER` and `FIRST`, dropping columns, and index name derivation
- [x] 9.5 Cover that an alter callback recording no operations throws rather than emitting invalid SQL
- [x] 9.6 Add `query()` and `last_error` to `tests/Support/Database/TestWpdb.php`, recording issued SQL, if `SchemaManager` is covered end to end
- [x] 9.7 Run `composer run phpcs && composer run test:unit` — everything green

## 10. Documentation

- [x] 10.1 Write `docs/migrations.md` matching the depth of `docs/sessions.md`: running and tracking migrations, batches and rollback, the `Schema::table()` verb reference, column modifiers, and the CLI commands
- [x] 10.2 Document that `rename_column()` rebuilds the column definition and issues `CHANGE COLUMN` for cross-version support
- [x] 10.3 Document the foreign key naming caveat — tables created before this change retain server-assigned `_ibfk_N` names and must be dropped by explicit name
- [x] 10.4 Document the upgrade note: the first `migrate` after upgrading backfills every existing migration into batch 1, so a rollback immediately afterwards would unwind the whole schema
- [x] 10.5 Update the command list in `README.md` to include `migrate:status` and `migrate:rollback`

## 11. End-to-end verification against MySQL

- [x] 11.1 Add `example/database/migrations/AlterBlogsTableAddSlug.php` exercising an added column with positioning, a changed column, a renamed column, a dropped column, and an index — and register it in `example/config/migrations.php`
- [x] 11.2 Run `make scope` (required after every `src/` edit — the example consumes a PHP-Scoper copy), then `make wp CMD="kirki migrate:fresh"` and confirm the database ends up with the full schema rather than no tables
- [x] 11.3 Run `make wp CMD="kirki migrate:status"` and confirm the new migration reports pending
- [x] 11.4 Run `make wp CMD="kirki migrate"`, then `migrate:status` again, and confirm the migration reports applied in a new batch
- [x] 11.5 Run `make wp CMD="kirki migrate"` a second time and confirm it is a **no-op** — the core proof that history tracking works
- [x] 11.6 Run `make wp CMD="kirki migrate:rollback"` and confirm only the new batch is undone while the create migrations remain applied
- [x] 11.7 Run `make wp CMD="db query 'SHOW CREATE TABLE wp_framework_blogs'"` and confirm foreign keys appear under the conventional `blogs_{column}_foreign` names
