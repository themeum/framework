## Context

See `proposal.md` — Why. This section covers only the existing structure that constrains the approach.

**The migrator.** `Migrator` holds a `MigrationRepository` and exposes `run()`, `rollback()`, `fresh()`. Migrations are resolved from the container tag `app.migrations`, populated by `CoreServiceProvider::register_migrations()` from the ordered array returned by `config/migrations.php`. They are **objects, not files** — there is no filesystem scanning and no timestamp in the filename, so registration order in that array *is* run order. History lives in a single WordPress option (`OptionKeys::MIGRATIONS`) reached through the `Option` facade, which prefixes the key and caches reads; the repo never calls `get_option`/`update_option` directly.

**The schema builder.** `Schema` (facade) → `SchemaManager` → `Structure` (the blueprint) → `Compiler` (the SQL grammar). Three facts shape everything below:

1. `Structure::get_table_structure()` hardcodes `compile_create`, and `Compiler::compile_create_table()` welds `CREATE TABLE IF NOT EXISTS %s (%s)` into the same method that assembles columns and commands. There is no seam that yields a reusable column list.
2. `Compiler::compile_column()` is already ALTER-shaped by accident: it emits `` `name` type modifiers `` with no leading comma or keyword — exactly the fragment `ADD COLUMN` and `MODIFY COLUMN` need.
3. `Definition extends Fluent` is an **empty class**. Every modifier (`nullable`, `default`, `unsigned`) is `Fluent::__call` magic writing an attribute. Any method name works.

**Execution.** No `dbDelta`. `SchemaManager` runs raw `$wpdb->query()` and detects failure by inspecting `$wpdb->last_error` afterwards, not by the return value.

**Constraints.** PHP 7.4 floor. Custom PHPCS sniffs enforce snake_case methods, no `private`, no `final`, `static::` over `self::`, a fixed file-header order, and a docblock on every method and property with tags ordered `@param`, `@return`, `@throws`, `@since`. `example/` consumes the library through a path repo plus PHP-Scoper, so `make scope` is required after every `src/` edit before the example plugin sees a change.

## Goals / Non-Goals

**Goals:**

- Make repeated `migrate` runs safe by construction, so non-idempotent operations can exist
- Reuse the existing column compilation path for ALTER rather than writing a second grammar, so column types and modifiers cannot drift between create and alter
- Keep one builder type in migration signatures
- Leave the CREATE path's observable output identical except for the deliberate FK naming change

**Non-Goals:**

- Filesystem or timestamp-based migration discovery — registration stays the `config/migrations.php` array
- A `migrate:reset` command — `migrate:fresh` already covers full teardown
- Transactional DDL — MySQL does not offer it; atomicity is pursued only within a single statement
- Restructuring `Structure::$commands` from its keyed map into a flat verb-tagged list
- Fixing pre-existing issues not widened by this change: the unused `$prefix` local in `QueryCompiler::wrap_table()`, unescaped values in `get_default_value()`/`type_enum()`/`type_set()`, and the missing `json()` column type

## Decisions

### 1. History records a batch and a timestamp, not a boolean

`[FQCN => ['batch' => N, 'ran_at' => 'Y-m-d H:i:s']]`.

Batches are what make rollback mean "undo the last run" instead of "destroy everything", and they are the unit the spec's rollback requirements are written against. The timestamp costs one field and answers "which deploy introduced this" during incident triage.

*Alternative rejected:* keeping the existing `[FQCN => true]` and merely uncommenting the persistence line. Smallest diff, but rollback could then only ever unwind everything at once.

A read-shim normalizes a legacy `true` value to `['batch' => 1, 'ran_at' => null]`. The released package never wrote history — the persistence line was commented out from the file's first commit — but `Migrator::rollback()` writes the old shape and is reachable from application code via the `migrator()` helper, so legacy data is possible and the shim is cheap.

### 2. History is written through, not accumulated

`record_migration()` is called immediately after each successful `up()`. The batch number is resolved **once** before the loop so every migration in one invocation shares it.

`run()` currently accumulates into a local array and would persist once at the end. Under that model a failure on migration 3 discards the record of 1 and 2, which already executed. Today that is invisible because `CREATE TABLE IF NOT EXISTS` is idempotent; with ALTER it guarantees the next run re-applies `ADD COLUMN` against a column that now exists, throwing, and wedging the migration path permanently.

*Alternative rejected:* one write in a `finally`. It survives exceptions but not fatals, timeouts, or OOM — and long-running `ALTER TABLE` on a large table under WP-CLI is exactly where a timeout happens. N option writes per deploy is not a cost worth optimizing.

### 3. One `Structure` with a mode, not a subclass

`Structure` gains `MODE_CREATE`/`MODE_ALTER` constants, a `$mode` property, an **optional** third constructor parameter, and a public `is_altering()`. `get_table_structure()` branches on it.

The optional parameter matters: `TestCase::make_structure()` and every existing caller construct with two arguments and must keep working unchanged.

*Alternative rejected:* `AlterStructure extends Structure`. It mirrors the repo's existing `ForeignKeyDefinition extends Definition` pattern and keeps create-mode's API clean, but it forces two imports and two type-hints on migration authors and doubles the explanation surface in docs and stubs. Laravel uses a single `Blueprint` for both, and the framework follows Laravel's verbs.

*Alternative rejected:* rewriting `$commands` into a flat verb-tagged list. Cleanest long term and it would fix the keyed map's quirks, but it puts every existing migration in the blast radius of a change whose CREATE-path risk should stay near zero.

Alter-only verbs (`drop_column`, `rename_column`, `drop_index`, `drop_unique`, `drop_primary`, `drop_foreign`) throw when `!is_altering()`, so the cost of the shared class is a guard clause rather than a silent misuse.

### 4. `rename_column()` introspects and emits `CHANGE COLUMN`

One code path. `Compiler::compile_database_columns()` already selects `column_type`, `is_nullable`, `column_default`, `extra`, and `column_comment` from `information_schema`; the rename reads the live definition, rebuilds it, and emits `CHANGE COLUMN \`old\` \`new\` <definition>`.

`RENAME COLUMN` is the obvious alternative and is rejected because it requires MySQL 8.0.1+ or MariaDB 10.5.2+, and shared WordPress hosting still runs MySQL 5.7 in quantity. Detecting support is a trap rather than a chore: MariaDB reports version `10.4`, which passes a naive `>= 8.0` comparison while lacking the syntax, so correct detection requires string-matching `MariaDB` in `db_server_info()` and maintaining two version thresholds. Writing the introspection fallback is unavoidable in that design, so the branch only adds a way to be wrong.

Two details the implementation must get right: the table name must be **prefixed** before the `information_schema` lookup, matching `SchemaManager::get_columns()`; and a `column_default` of `CURRENT_TIMESTAMP` must be emitted raw rather than quoted. A missing column raises an error naming it.

### 5. Foreign keys get explicit conventional names in both paths

`CONSTRAINT \`{unprefixed_table}_{column}_foreign\``, matching the `{table}_{cols}_index` / `_unique` convention `Structure` already uses for indexes. `Structure::foreign()` already accepts a `$name` that the compiler currently ignores; it becomes an override.

`drop_foreign()` needs a stable target. Without naming, MySQL assigns `_ibfk_N` by creation order — unstable and undiscoverable from the migration source.

This changes existing `CREATE TABLE` output, which is why the create path carries a dedicated regression test. Tables installed before this ships keep their `_ibfk_N` names and can only be dropped by explicit name; that asymmetry is permanent and belongs in the docs.

*Alternative rejected:* naming only in the alter path. Zero risk to CREATE, but it makes a foreign key's name depend on where it was born, which is a worse footgun than the one-time output change.

### 6. One callback compiles to one `ALTER TABLE`

All clauses are comma-joined into a single statement. MySQL DDL is not transactional, so a single statement is the only available all-or-nothing boundary, and it rebuilds the table once instead of N times. MySQL processes `DROP` before `ADD` within one statement, so dropping and re-adding the same index name in one callback works. An empty clause list throws rather than emitting invalid SQL.

`RENAME TABLE` cannot be combined and stays a separate statement issued by `SchemaManager::rename()`.

*Alternative rejected:* one statement per operation. Easier error attribution, but N rebuilds on large tables and — because history is not yet written for that migration — a failure on clause 3 of 5 leaves a permanently half-migrated table.

### 7. `compile_alter()` reuses `compile_column()` verbatim

Columns are partitioned on the `change` attribute into `MODIFY COLUMN` and `ADD COLUMN`; both fragments come from the existing `compile_column()`.

This is load-bearing, not merely tidy. `type_timestamp()` and `type_datetime()` **mutate the column** to install the `CURRENT_TIMESTAMP` default while computing the type string, and only produce correct SQL because `add_modifiers()` runs `apply_default` *after* `get_column_type`. Any reimplementation of the fragment silently breaks `use_current()`.

The command generators are *not* reused. `generate_primary`/`generate_unique`/`generate_index` emit leading spaces and CREATE-body keywords (`KEY`, not `INDEX`), so `compile_alter` gets dedicated ALTER-shaped generators. `extract_commands_from_columns()` must be called exactly once — it mutates the structure and appends, so a second call duplicates every unique and index command. `compile_create_engine`/`compile_create_encoding` are not called; they are invalid on an ALTER.

### 8. Positioning is a modifier, gated on mode

`'position'` is appended as the **last** entry of `Compiler::$modifiers`, with `apply_position()` reading the `after` and `first` attributes. `AFTER` must trail all other modifiers, and it is a syntax error inside a `CREATE TABLE` column definition — so the applier emits only when `$structure->is_altering()`. The modifier applier signature already receives `$structure`, so no plumbing is needed.

`->change()`, `->after()`, and `->first()` require **no code on `Definition`** — `Fluent::__call` stores any unknown method as an attribute.

### 9. Alter operations are strict

No operation guards itself against pre-existing or missing objects. Read-only `has_table()`/`has_column()`/`has_columns()` are exposed so a caller can decide, but adds and drops surface the database's error.

With history tracking working, a migration does not re-run, so a duplicate-column error means a genuine bug — either lost history or a mistake in the migration. Auto-guarding would turn a mistyped column name into a silent no-op and cost an extra `information_schema` read per clause.

### 10. `fresh()` clears history and undoes only what was applied

`FreshCommand` calls `migrator()->fresh()` and then `MigrateCommand`. `fresh()` drops every table but does not touch the history option. The instant persistence is live, the subsequent `migrate` would see every migration as already applied and skip all of them, leaving a database with no tables. `remove_migrations()` goes inside the existing `finally`, alongside the foreign-key-check restore.

Implementation surfaced a second defect in the same method, invisible while every migration was a `Schema::create()`. `fresh()` calls `down()` on **every registered migration in forward order**. `drop_if_exists()` is unconditionally safe, so that never failed — but an alter migration's `down()` reverses a specific change and throws when that change was never applied:

```
Uncaught Exception: Column [summary] does not exist on table [framework_blogs]
```

`fresh()` therefore undoes only migrations recorded as applied, in reverse registration order. It becomes "roll back everything, then re-migrate", sharing its reasoning with `rollback()`. Reverse order also fixes a latent teardown-ordering issue: forward order drops a parent table before its child and survives only because foreign key checks are disabled.

*Alternative rejected:* keep the unconditional teardown and swallow errors from migrations that were never applied. It preserves today's "always tear down" recovery behaviour even with no history, but a genuinely broken `down()` then looks identical to a skipped one.

*Alternative rejected:* drop all tables directly, as Laravel's `migrate:fresh` does. Semantically cleanest and immune to `down()` bugs, but the framework has no way to know which tables belong to it beyond prefix matching, which would drop tables it never created.

The trade-off accepted: on a legacy installation where tables exist but history is empty, `fresh()` tears nothing down. The following `migrate` backfills history, closing the window permanently after one run. No alter migration can predate this release, so nothing needing reversal can be stranded.

### 11. `rollback()` runs in reverse under a foreign key guard

Beyond scoping to the last N batches, `rollback()` needs two corrections. It iterates **forward** registration order, so a batch containing a parent table registered before its child drops the parent first and trips the constraint. And unlike `fresh()`, it has no foreign key guard at all. Both are fixed here: reverse iteration, wrapped in the same `try`/`finally` foreign-key toggle `fresh()` already uses.

### 12. The generator registers what it creates

`make:migration` writes to `database_path('migrations')` but never adds the class to `config/migrations.php`, and that array is the *only* discovery mechanism. A generated migration is therefore invisible until hand-registered — a footgun that gets sharper once ordering carries batch semantics. The generator appends the fully-qualified class name to the end of the array, which is always correct because the array is run order and a new migration should run last. `--table=X` selects a new alter stub. Because the config file is user-owned, a missing or unparseable file produces a warning and a still-written migration file, never a fatal.

## Risks / Trade-offs

- **Named FK constraints change existing `CREATE TABLE` output** → A dedicated create-path regression test asserting the exact SQL, plus a documented caveat that pre-existing tables retain `_ibfk_N` and must be dropped by explicit name.
- **First `migrate` after upgrading backfills every migration into one batch** → Installations that already ran migrations have no history, so the first run re-executes every `CREATE TABLE IF NOT EXISTS` (harmless) and records them all as batch 1. A subsequent `migrate:rollback` would therefore unwind the entire schema. Documented in `docs/migrations.md` as an upgrade note; `migrate:status` makes the batch grouping visible before anyone rolls back.
- **`migrate` becoming a no-op is a behavioural break** → Anyone relying on repeated runs to re-assert schema loses that. This is the intended fix, called out as BREAKING in the proposal, with `migrate:fresh` as the re-assert path.
- **Rename introspection can lose exotic column attributes** → Rebuilding from `information_schema` covers type, nullability, default, comment, and auto-increment. Generated columns and unusual per-column charsets are not reconstructed. Renaming such a column is out of the tested envelope and should use a hand-written statement.
- **A single combined `ALTER` gives coarse error attribution** → When one clause is invalid the whole statement fails and MySQL names only the offending fragment. Accepted deliberately in exchange for all-or-nothing behaviour; the compiler tests assert exact SQL so clause construction is verifiable without a database.
- **Write-through history costs N option writes per run** → Negligible: migrations run once per deploy, and the `Option` manager caches.
- **`example/` runs a PHP-Scoper copy of the library** → Any verification against real MySQL that skips `make scope` tests stale code. Every example command in the task list is prefixed with it.

## Migration Plan

Ship in one release, implemented history-first. Part A (history, commands, `fresh()`/`rollback()` fixes) is landed and green before Part B (ALTER) begins, because ALTER without history is the unsafe combination.

No data migration is required: absent history is the correct representation of "nothing recorded", and the first run after upgrade backfills it. Rollback strategy for the release itself is a package version revert — the history option left behind is simply ignored by the older code, which never read it meaningfully.
