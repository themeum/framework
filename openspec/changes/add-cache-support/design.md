## Context

See `proposal.md` — Why. This section records only the constraints that shape the approach.

**Framework constraints.** PHP 7.4 floor. `SnakeCaseMethodSniff` forbids camelCase methods and
`NoPrivateSniff` forbids `private`. `config()` returns the supplied default when the file is
absent, and `example/config/session.php` establishes that every config key is optional.
`SomoyInterface extends DateTimeInterface`, so accepting `DateTimeInterface` covers the
framework's own date type with no special casing — but `Somoy` has no time-freezing facility.
`EventManager` dispatches objects against a listener map built from `config/listeners.cache.php`
and has no runtime `listen()`. `Filesystem` is a `WP_Filesystem` wrapper.

**WordPress constraints**, all verified against core:

- `get_transient()` returns `false` for both a miss and a stored `false`, and exposes no `$found` flag.
- `wp_options.option_name` is `varchar(191)`; the `_transient_timeout_` prefix is 19 characters, so a transient name is capped at **172**. `wpdb::$incompatible_modes` strips `STRICT_TRANS_TABLES`, so overflow is **silently truncated**, not an error.
- Under a persistent object cache, transients bypass `wp_options` entirely and are not enumerable.
- `set_transient($k, $v, 0)` calls `add_option($opt, $val, '', true)` — an explicit boolean — and `wp_determine_option_autoload_value()` short-circuits on explicit booleans, so the 150,000-byte `wp_max_autoloaded_option_size` guard **does not apply**. A no-expiry transient is autoloaded, unguarded, on every request.
- Without an object cache, each first-access `get_transient()` costs **two** option reads.
- `wp_salt()` lives in `pluggable.php`, which loads *after* plugins — it cannot be called from a constructor.

## Goals / Non-Goals

**Goals**

- One correct cache implementation with Laravel's vocabulary, so knowledge transfers.
- Uniform observable behaviour across drivers, so a test on `array` predicts production on `database`.
- Every WordPress-forced divergence from Laravel deliberate, minimal, and documented.
- Testable without `sleep()` or a live database.

**Non-Goals**

- Atomic locking and everything derived from it. Excluded by decision; its absence is what makes `add()`, `increment()` and `flexible()`'s stampede guard best-effort.
- Tagged cache, the `failover` driver, `sear`, `rememberWithWarmth`.
- A custom cache table. Viable and in some respects cleaner, but it forfeits free object-cache acceleration — see Decision 3.

## Decisions

### 1. Naming: Laravel's verbs, the framework's casing

`remember_forever`, `put_many`, `get_multiple`. *Alternatives:* exact camelCase with a PHPCS
exclusion for `src/Cache/` — rejected because one subsystem reading differently from all the
others is a worse cost than a dozen renamed methods, and an exclusion invites more; or
snake_case with camelCase aliases via `__call` — rejected as it doubles the documented surface
and makes every code review pick a spelling.

### 2. Three layers: manager → repository → store

`CacheManager` resolves and memoises repositories per store name and forwards unknown calls to
the default one; `Repository` holds every verb exactly once; `Store` is a ten-method primitive
contract. This mirrors the existing `DatabaseManager` → `Connection` → `QueryCompiler` shape.

*Alternative:* two layers with a fat store contract, as the session subsystem does. Rejected —
`remember`, `flexible`, `pull`, `add`, `touch` and the typed getters would be implemented three
times, and those are precisely the methods with subtle semantics, so the copies would drift.
The split also makes `memo()` a natural decorator rather than a special case, and drops the cost
of a third-party driver from 25 methods to 10.

### 3. Transients, not a custom table

*Decisive reason:* under a persistent object-cache drop-in — most managed WordPress hosting —
transients bypass MySQL entirely, so the cache runs at memory speed for no code. A custom table
never can. Secondary: nothing to install, version, or migrate.

*Accepted costs:* two option reads per first access on hosts without a drop-in; and four of the
mitigations below exist only because of this choice (envelope miss-sentinel, version-bump flush,
`forever_ttl`, non-atomic counters). A `table` driver can be added later as a fourth store
without touching anything else.

### 4. A uniform value envelope

Every store persists a marked array rather than the raw value:

```php
[
    '__framework_cache' => 1,          // marker + format version
    'key'               => $raw_key,   // debuggability; collision → miss
    'value'             => $value,
    'created_at'        => int,
    'expires_at'        => int|null,   // null = logical forever
    'fresh_until'       => int|null,   // flexible() only
]
```

A read that is not a marked array is a miss; a read whose stored `key` differs from the
requested one is also a miss, so a hash collision can never return the wrong value.

This one structure solves four problems at once: it restores the miss/`false` distinction the
transient API destroys; it *is* the expiry mechanism for the file store, which has no TTL; it
lets the array store honour TTLs so tests match production; and it carries `flexible()`'s
windows in one entry where Laravel needs two.

*Alternative:* a `CacheEntry` object — rejected because transients outlive plugin deactivation,
and unserializing an object whose class is no longer loadable yields `__PHP_Incomplete_Class`
and a fatal on property access. A plain array degrades to a miss.

### 5. Keys: always hashed, namespace version-prefixed

```php
hash_hmac('sha256', $blog_id . '|' . $key, wp_salt('nonce'))   // salt computed lazily
```

- Transients: `{app_prefix}c{version}_{hash:32}` — a fixed ~45 characters, permanently inside the 172 limit.
- Files: the full 64-character hash, two-level `ab/cd/` fan-out.

*Rationale:* the overflow failure is silent truncation into a collision, and it is
data-dependent — a key built from a URL passes every test and breaks on one long input in
production. Hashing makes the behaviour deterministic and identical on every host.

*Note for implementers:* the justification is **length determinism**, not "one code path shared
with the file store". The file store hashes unconditionally for path safety and
web-unguessability (Decision 8), which are file-specific concerns and would not have forced the
transient store's hand.

*Alternatives:* hash only on overflow — legible option names for most keys, but two code paths;
or validate and throw — fully legible, but exports a data-dependent runtime failure to callers
the framework does not control.

### 6. `flush()` by version bump

Increment `{prefix}_cache_version` (a site option, or a network option when the store is
network-wide); every prior key becomes unreachable at once. O(1), and correct under an object
cache where there are no rows to delete. Then, **only** when `wp_using_ext_object_cache()` is
false, a best-effort `$wpdb` sweep of the previous version's rows so nothing is orphaned on disk.

*Alternatives:* a direct SQL sweep alone — silently a no-op under an object cache while still
reporting success; or a maintained key index — an extra read and write on every `put`, an index
that can outgrow the row, and lost entries under concurrent writes with no locks available.

The file store flushes by deleting its tree instead, because files *are* enumerable. Stores
having different flush mechanics is normal; Laravel's differ too.

### 7. `forever()` = logical forever over a long finite TTL

The envelope records `expires_at => null`, so the entry never logically expires; the WP transient
receives `config('cache.stores.database.forever_ttl')`, default `10 * YEAR_IN_SECONDS`. Because
that lifetime is non-zero, WordPress marks the option **non-autoloaded**.

*Why this matters:* as established in Context, core's own autoload size guard does not apply to
no-expiry transients. A 300KB `forever()` value would be read and unserialized on every request
site-wide — a cost paid inside `wp_load_alloptions()`, before plugin code runs, so it is close to
undiagnosable from the plugin's own profiling.

*Why the divergence is acceptable:* Laravel's own documentation states that `forever` entries may
be evicted by the Memcached driver, so `forever` is explicitly not a durability guarantee there
either. A ten-year lifetime is *more* durable than the behaviour Laravel already accepts. The
knob may be set to `0` for literal never-expiry.

### 8. File store: uploads, hardened, via the `File` facade

**Location** `wp_upload_dir()['basedir'] . '/{prefix}-cache-{salted-suffix}/'`, overridable.
Uploads is the only location that is reliably writable, survives plugin updates, and is not
shared with other caching plugins' cleanup routines.

**Protection**, four layers because each covers a different server configuration: `index.php`
against listing; `.htaccess` (Apache only — nginx ignores it); a `.php` extension with a leading
`<?php exit; ?>` guard, safe even for attacker-influenced payloads because `exit` runs before the
payload is reached; and salted-unguessable filenames, which is the only layer that works
regardless of server configuration and is therefore the real control.

**I/O through `Filesystem`/`File`** for consistency with the rest of the framework, which
requires working around three of its properties: a missing file throws, so pre-check with
`File::is_file()` and catch anyway for the TOCTOU window; writes are not atomic, so write to a
temp path and `File::move()` it into place — `Filesystem::move()` delegates to
`$wp_filesystem->move(..., true)`, which is `rename()` on the Direct transport; and the
constructor calls `WP_Filesystem()`, so resolve it lazily.

**Degradation** — see Decision 9.

### 9. The file store degrades rather than fatals

`WP_Filesystem()` may select an FTP or SSH transport (making every cache read a network round
trip), or fail outright, in which case `Filesystem::__construct()` throws. Either would surface
from an ordinary `Cache::get()`.

Therefore: resolve lazily inside `try`/`catch` and check `get_filesystem_method() !== 'direct'`.
On either condition, fall back to `config('cache.stores.file.fallback')` — default `database`,
which works on every host — log a warning once per request, and call `_doing_it_wrong()` when
`app()->is_dev_mode()`.

*Rationale:* a cache is an optimisation and must never be the reason a site is down. Throwing
would be consistent with `CoreServiceProvider`'s throw-on-unknown-session-driver, but that catches
a *typo the developer can fix*; this catches a *property of the host they may not control*, and
the failure would land on visitors.

### 10. TTL: full parity, plus a seamed clock

Accept `int` | `DateTimeInterface` | `DateInterval` | `null`. Zero, negative, or a past date
forgets the key and reports failure, matching Laravel — ported code will rely on it.
`DateTimeInterface` costs about six lines and buys `Somoy` interoperability free.

All time is read through **one overridable UTC-epoch method**. Two reasons: `current_time()`
returns a timezone-shifted value while `set_transient()` computes `time() + $expiration`, so
mixing them makes every TTL wrong by the site's UTC offset — passing tests in UTC and failing in
Asia/Dhaka; and `Somoy` has no freeze facility, so without a seam the expiry logic,
`flexible()`'s windows and `forever_ttl` could only be tested with real `sleep()`.

### 11. `flexible()` refreshes on `shutdown` after `fastcgi_finish_request()`

The handler calls `fastcgi_finish_request()` / `litespeed_finish_request()` when available — the
visitor already has their response — then recomputes. Where neither exists it still runs on
`shutdown`, delaying that one response, which is **no worse than `remember()`**.

*Alternative:* `wp_schedule_single_event(time(), …)` — genuinely out of process, but never fires
where `DISABLE_WP_CRON` is set or loopback requests are blocked, so the feature would silently
stop working on a whole class of host. A mechanism that degrades to baseline beats one that
degrades to nothing.

Laravel's `$lock` parameter is **omitted**, not accepted-and-ignored, so its absence is visible at
the call site.

### 12. Five events, gated before construction

`CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`, `CacheFlushed`. `EventManager::dispatch()`
is already cheap when nothing listens, but **constructing** the event object is not — so add
`EventManager::has_listeners()` (a one-line `isset()` against the map it already holds) and check
it first. An unlistened event then costs one `isset()`.

The remaining twelve Laravel events are pre-events and failure events that largely serve Laravel
internals and Telescope; twelve more classes to write, document and test for near-zero use.

### 13. `memo()` coherence via a shared table

`MemoizedStore` decorates an inner store and is wrapped in a `Repository`; `CacheManager::memo()`
must return the **same** instance per store name or memoization never accumulates. Misses are
memoized too.

Coherence comes from a static per-request table keyed by store name **and blog id**, invalidated
from `Repository`'s write paths — so `Cache::put('x', …)` is seen by `Cache::memo()->get('x')` in
the same request. This closes a hole Laravel's own `memo` driver has.

*Alternative:* subscribe to the Decision 12 events — architecturally tidier, but `EventManager`
has no runtime `listen()`, so it needs a *second* incursion there, and it would stop working for
any store with `'events' => false`. More machinery, weaker guarantee.

### 14. Counters are best-effort, and say so

No store can offer atomicity: transients are read-modify-write; the envelope rules out SQL
arithmetic and `wp_cache_incr()`; and `WP_Filesystem` exposes no read-modify-write under `flock()`.
So the contract is uniform across drivers rather than a per-driver quirk.

*Alternative:* a compare-and-set retry loop — rejected, and this is the important one: without an
atomic primitive it makes the race *rarer*, not *absent*, which is exactly how a rate limiter
ships broken. A documented race is manageable; a half-fixed one invites false confidence. The
docs point at `DB::table()` with a raw `SET col = col + 1` for exact counts.

### 15. Multisite scoping is a correctness fix, not a feature

Folding `get_current_blog_id()` into the key hash and the memo table key is **required**: without
it, `switch_to_blog()` makes the Decision 13 memo table return another site's value — a
cross-tenant read in a single request, the worst failure mode in this design. `wp_upload_dir()`
is already per-site, but a configured fixed `path` would otherwise collide across the network.

On top of that, an opt-in `'network' => true` swaps the database store to the `*_site_transient`
functions and the version counter to network options — about fifteen lines, enabling something a
plugin author cannot add from outside the framework.

## Risks / Trade-offs

- **A cache key collides after hashing** → Two independent mitigations: HMAC-SHA-256 truncated to 128 bits makes accidental collision statistically irrelevant, and the envelope's stored `key` is compared on read so *any* collision, accidental or engineered, degrades to a miss rather than a wrong value.
- **Opaque option names hinder production debugging** → The envelope carries the original key, so one `unserialize` of `option_value` reveals it; `cache:forget` computes the stored identifier; the hashing helper is public.
- **`flexible()` stampede: concurrent stale requests each refresh** → A short-lived marker collapses most of it, but it is check-then-set, not atomic. Documented on the method as a direct consequence of excluding locks.
- **Counters lose increments under concurrency** → Documented, with `DB::table()` named as the exact-count alternative. Explicitly *not* mitigated by a retry loop (Decision 14).
- **`forever()` values vanish after ten years** → Only affects unrecomputable data, which belongs in `Option::set()`, not a cache. `forever_ttl => 0` restores literal never-expiry.
- **The GC sweep times out on a large cache directory** → Sweep a rotating slice of the 256 shards with a cursor in an option and a per-run file cap; never a full scan. `'gc' => false` disables it.
- **File cache entries readable over HTTP** → Four defence layers, of which salted-unguessable naming works on every server configuration; the others cover Apache and PHP-executing hosts respectively.
- **Salt rotation silently invalidates the whole cache** → Accepted: harmless for a cache, and documented.
- **Transients cost two option reads per first access without an object cache** → Accepted (Decision 3); revisit by adding a `table` driver if it ever measures as a problem.

## Migration Plan

Purely additive; there is nothing to migrate. No schema, no data, no changed behaviour in any
existing subsystem. Rollback is removal of the new files plus reverting three small edits to
`Application.php`, `helpers.php` and `EventManager.php`.

One deployment note: the file store's GC event is registered on boot when that store is
configured, and should be unscheduled if the store is later removed from configuration.

## Open Questions

- The exact coercion and error behaviour of the typed getters (`string`, `integer`, `float`, `boolean`, `array`) must be read off `Illuminate\Cache\Repository` at implementation time rather than assumed. This affects only those five methods' internals, not the specs, the architecture, or the task breakdown.
