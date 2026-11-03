# Migrations

This guide covers running and tracking migrations, and evolving tables that already exist. The API follows Laravel's schema builder, adapted to the framework's snake_case method naming and the WordPress request lifecycle.

## Table of contents

1. [Upgrade note: read this first](#1-upgrade-note-read-this-first)
2. [How migrations are registered](#2-how-migrations-are-registered)
3. [Running and tracking migrations](#3-running-and-tracking-migrations)
4. [Batches and rollback](#4-batches-and-rollback)
5. [Creating tables](#5-creating-tables)
6. [Altering existing tables](#6-altering-existing-tables)
7. [Column modifiers](#7-column-modifiers)
8. [Indexes, keys, and foreign keys](#8-indexes-keys-and-foreign-keys)
9. [Renaming columns](#9-renaming-columns)
10. [Inspecting the schema](#10-inspecting-the-schema)
11. [CLI reference](#11-cli-reference)

---

## 1. Upgrade note: read this first

Before this release the migrator never recorded what it ran. Every `wp kirki migrate` re-executed every registered migration, which was harmless only because every migration was a `Schema::create()` compiling to `CREATE TABLE IF NOT EXISTS`.

Two consequences on your first run after upgrading:

**Every existing migration is backfilled into batch 1.** Your installation has no recorded history, so the first `migrate` re-runs every `CREATE TABLE IF NOT EXISTS` (a no-op against tables that already exist) and records them all as a single batch. A `migrate:rollback` immediately afterwards would therefore unwind your entire schema, not just the most recent piece of work. Run `wp kirki migrate:status` to see the batch grouping before you roll anything back.

**`wp kirki migrate` is now a no-op when nothing is pending.** If you were relying on repeated runs to re-assert schema, use `wp kirki migrate:fresh` instead.

**Foreign keys created before this release keep their server-assigned names.** See [section 8](#8-indexes-keys-and-foreign-keys).

## 2. How migrations are registered

Migrations are classes implementing `Framework\Contracts\Migration`, listed in `config/migrations.php`. That array **is** the run order — there is no filesystem scanning and no timestamp in the filename, so dependency order is yours to control:

```php
return [
    CreateCategoriesTable::class,
    CreateTagsTable::class,
    CreateBlogsTable::class,
];
```

`make:migration` appends each generated class to the end of that array for you. A new migration should run last, so appending is always correct. If the config file is missing or cannot be parsed, the migration file is still written and the command warns you to register it by hand.

## 3. Running and tracking migrations

```bash
wp kirki migrate
```

Only migrations with no recorded history execute. Each one is recorded **immediately after it succeeds**, not at the end of the run. That matters: if the third of five migrations throws, the first two stay recorded and the next `migrate` resumes at the third rather than re-applying work that already landed.

This is what makes non-idempotent operations safe. `ADD COLUMN` run twice throws; because history is recorded, it never runs twice.

Check what has and has not run:

```bash
wp kirki migrate:status
```

```
+-----+-----------------------+-------+
| Ran | Migration             | Batch |
+-----+-----------------------+-------+
| Yes | CreateCategoriesTable | 1     |
| Yes | CreateBlogsTable      | 1     |
| No  | AddSlugToBlogsTable   |       |
+-----+-----------------------+-------+
```

History is stored in a single WordPress option, per site on multisite.

## 4. Batches and rollback

Every migration applied during one `migrate` invocation shares a **batch** number. Each run takes a number higher than any recorded batch. Rollback works in batches, so it means "undo the last run" rather than "destroy everything":

```bash
wp kirki migrate:rollback
wp kirki migrate:rollback --step=2
```

Within a batch, migrations are undone in the **reverse** of their registration order, with foreign key checks suspended for the duration and restored even if a step fails. That is what lets a batch containing a parent table and a child that references it unwind cleanly.

Rolled-back migrations are removed from history, so they are pending again and the next `migrate` re-applies them.

`wp kirki migrate:fresh` drops every table **and clears the history**, so the migrate pass that follows re-applies everything.

## 5. Creating tables

```php
use Framework\Database\Schema\Structure;
use Framework\Supports\Facades\Schema;

Schema::create('posts', function (Structure $table) {
    $table->id();
    $table->string('title');
    $table->text('body')->nullable();
    $table->enum('status', ['draft', 'published'])->default('draft');
    $table->timestamps();
});
```

`timestamps()` adds `created_at` (defaulting to `CURRENT_TIMESTAMP`) and a nullable `updated_at` as `TIMESTAMP` columns. `datetimes()` adds the same pair as `DATETIME` columns, which avoids `TIMESTAMP`'s 2038 limit and its time zone conversion. Both accept an optional precision, e.g. `$table->datetimes(6)`. Unlike Laravel's `datetimes()`, where both columns are nullable, `created_at` here defaults to `CURRENT_TIMESTAMP`, the same as `timestamps()`.

## 6. Altering existing tables

`Schema::table()` is the `ALTER TABLE` counterpart. It takes the **same** `Structure` type-hint as `Schema::create()`, so migrations need only one import:

```php
Schema::table('posts', function (Structure $table) {
    $table->string('slug')->after('title');
    $table->string('title', 500)->change();
    $table->drop_column('legacy_ref');
    $table->unique(['slug']);
});
```

Everything recorded by one callback compiles to a **single** `ALTER TABLE` statement with comma-joined clauses:

```sql
ALTER TABLE `wp_posts`
  ADD COLUMN `slug` varchar(255) not null AFTER `title`,
  MODIFY COLUMN `title` varchar(500) not null,
  ADD UNIQUE KEY `posts_slug_unique` (`slug`),
  DROP COLUMN `legacy_ref`
```

MySQL DDL is not transactional, so a single statement is the only available all-or-nothing boundary — if one clause is invalid, none of them take effect. It also rebuilds the table once instead of once per operation.

**Alter operations are strict.** Adding a column that already exists, or dropping one that does not, raises an error rather than silently skipping. With history tracking working, a migration does not re-run, so a duplicate-column error means a real bug — either lost history or a mistake in the migration. Use the [introspection helpers](#10-inspecting-the-schema) when you genuinely need to branch.

The alter-only verbs are `drop_column`, `rename_column`, `drop_index`, `drop_unique`, `drop_primary`, and `drop_foreign`. Calling any of them inside `Schema::create()` throws.

### Renaming a table

```php
Schema::rename('posts', 'articles');
```

This is issued as its own statement; `RENAME TABLE` cannot be combined into an `ALTER TABLE`.

## 7. Column modifiers

Declaring a column inside `Schema::table()` **adds** it. Mark it `->change()` to modify an existing one:

```php
$table->string('title', 500)->change();
```

`->change()` requires the **complete** intended definition. Modifiers you do not restate are not carried over from the column's current definition — a nullable column redeclared without `->nullable()` becomes `not null`. This matches Laravel.

Newly added columns can be positioned:

```php
$table->string('slug')->after('title');
$table->string('uuid')->first();
```

Positioning applies only when altering. `AFTER` is a syntax error inside a `CREATE TABLE` column definition, so it is silently ignored during creation.

| Modifier | Effect |
|----------|--------|
| `->nullable()` | Allow `NULL` |
| `->default($value)` | Set a default value |
| `->unsigned()` | Mark a numeric column unsigned |
| `->auto_increment()` | Auto-incrementing column |
| `->comment($text)` | Column comment |
| `->use_current()` | Default a timestamp to `CURRENT_TIMESTAMP` |
| `->change()` | Modify an existing column instead of adding one |
| `->after($column)` | Position after a named column (alter only) |
| `->first()` | Position before all other columns (alter only) |

## 8. Indexes, keys, and foreign keys

```php
Schema::table('posts', function (Structure $table) {
    $table->index(['status']);
    $table->unique(['slug']);
    $table->foreign('author_id')->references('id')->on('users')->cascade_on_delete();

    $table->drop_index(['status']);
    $table->drop_unique(['slug']);
    $table->drop_primary();
    $table->drop_foreign(['author_id']);
});
```

Names are derived from the table and columns — `posts_status_index`, `posts_slug_unique`, `posts_author_id_foreign` — so the drop verbs accept either the columns or an explicit name string:

```php
$table->drop_foreign(['author_id']);              // derives posts_author_id_foreign
$table->drop_foreign('posts_author_id_foreign');  // explicit
```

### Long key names

MySQL and MariaDB reject any identifier longer than 64 characters with error 1059. A derived name on a long table with several long columns can cross that line, so every key and constraint name — derived or explicit, index, unique, primary, or foreign — is passed through the same shortening step: names within the limit are left exactly as they are, and a name over the limit is truncated to 55 characters and suffixed with an 8-character hash of the full name.

```
organisation_membership_subscription_invoices_billing_contact_person_id_foreign
→ organisation_membership_subscription_invoices_billing_c_ac51c056
```

The hash is derived from the full name, so the shortened result is deterministic. `up()` and `down()` build the same name from the same columns and therefore agree — `drop_foreign(['billing_contact_person_id'])` drops exactly the constraint that `foreign('billing_contact_person_id')` created. Pass an explicit short name when you would rather read something meaningful in `SHOW CREATE TABLE`.

### Foreign key naming caveat

Foreign keys are now created as named constraints in **both** the create and alter paths:

```sql
CONSTRAINT `blogs_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `wp_categories` (`id`)
```

Before this release they were unnamed, so MySQL assigned `wp_blogs_ibfk_1`, `_ibfk_2`, and so on by creation order. **Tables created before this release keep those server-assigned names** and cannot be dropped by the derived name — you must pass the explicit `_ibfk_N` name, which you can find with:

```bash
wp db query "SHOW CREATE TABLE wp_blogs"
```

Tables created after this release get the predictable name, and `drop_foreign(['column'])` re-derives it.

## 9. Renaming columns

```php
$table->rename_column('desc', 'description');
```

You do not restate the column's type. The framework reads the live definition from `information_schema` and issues a `CHANGE COLUMN` that preserves the existing type, nullability, default, comment, and auto-increment behaviour:

```sql
ALTER TABLE `wp_posts` CHANGE COLUMN `desc` `description` text not null comment 'body'
```

`CHANGE COLUMN` is used rather than the shorter `RENAME COLUMN` deliberately. `RENAME COLUMN` requires MySQL 8.0.1+ or MariaDB 10.5.2+, and shared WordPress hosting still runs MySQL 5.7 in quantity. Detecting support reliably is worse than it looks — MariaDB 10.4 reports version `10.4`, which passes a naive `>= 8.0` check while lacking the syntax. One code path that works everywhere is simpler and safer.

Renaming a column that does not exist raises an error naming the column.

**Limits.** The rebuilt definition covers type, nullability, default, comment, and auto-increment. Generated columns and unusual per-column character sets are not reconstructed; rename those with a hand-written statement.

## 10. Inspecting the schema

```php
Schema::has_table('posts');
Schema::has_column('posts', 'slug');
Schema::has_columns('posts', ['slug', 'title']);
Schema::get_column_listing('posts');
Schema::get_columns('posts');
```

These are read-only. They let a caller decide what to do; they do not make the alter verbs forgiving.

## 11. CLI reference

| Command | Description |
|---------|-------------|
| `wp kirki migrate` | Run every pending migration |
| `wp kirki migrate:status` | Show each migration as applied or pending, with its batch |
| `wp kirki migrate:rollback` | Undo the most recent batch |
| `wp kirki migrate:rollback --step=N` | Undo the N most recent batches |
| `wp kirki migrate:fresh` | Drop all tables, clear history, re-run everything |
| `wp kirki make:migration <name>` | Generate a create migration |
| `wp kirki make:migration <name> --table=posts` | Generate an alter migration for an existing table |
| `wp kirki make:migration <name> --create=posts` | Generate a create migration for a named table |
