# Cache

This guide covers storing and retrieving values with a lifetime, across three storage drivers. The API follows Laravel's cache, adapted to the framework's snake_case method naming and to what WordPress can actually guarantee.

## Table of contents

1. [Quick start](#1-quick-start)
2. [Reading values](#2-reading-values)
3. [Storing values](#3-storing-values)
4. [Retrieve and store](#4-retrieve-and-store)
5. [Stale while revalidate](#5-stale-while-revalidate)
6. [Request-level memoization](#6-request-level-memoization)
7. [Removing values](#7-removing-values)
8. [Multiple stores](#8-multiple-stores)
9. [The drivers](#9-the-drivers)
10. [Configuration](#10-configuration)
11. [Events](#11-events)
12. [Multisite](#12-multisite)
13. [Where this differs from Laravel](#13-where-this-differs-from-laravel)
14. [CLI reference](#14-cli-reference)

---

## 1. Quick start

No configuration is required. The cache works the moment the framework boots, using the `database` driver, which stores values as WordPress transients.

```php
use Framework\Supports\Facades\Cache;

Cache::put('report', $data, 600);      // ten minutes
$data = Cache::get('report');

$users = Cache::remember('users', 3600, function () {
    return User::query()->get();
});
```

The `cache()` helper does the same thing:

```php
use function Framework\cache;

cache(['report' => $data], 600);       // write
$data = cache('report');               // read
cache()->remember('users', 3600, fn () => User::query()->get());
```

Method names use the framework's snake_case convention. Laravel's verbs are unchanged, so `rememberForever` is `remember_forever` and `putMany` is `put_many`. Single-word methods — `get`, `put`, `add`, `pull`, `forever`, `remember`, `flexible`, `memo`, `touch`, `forget`, `flush` — are spelled exactly as in Laravel.

---

## 2. Reading values

```php
Cache::get('key');                     // null when absent
Cache::get('key', 'default');
Cache::get('key', fn () => expensive()); // evaluated only on a miss

Cache::has('key');
Cache::missing('key');

Cache::many(['a', 'b']);                // ['a' => …, 'b' => null]
Cache::many(['a' => 'fallback']);       // per-key defaults
Cache::pull('key');                     // read, then remove
```

**Falsy values are cached correctly.** Storing `false`, `null`, `0` or `''` and reading it back returns exactly that value, and `has()` still reports the key as present:

```php
Cache::put('user_exists', false, 3600);

Cache::get('user_exists');   // false
Cache::has('user_exists');   // true
```

This matters more than it sounds. `get_transient()` returns `false` for both "missing" and "the value `false`", so caching a negative result with the raw WordPress API produces a cache that misses on every request forever. Every value is stored inside an envelope carrying the key and its lifetime, which is what keeps the two apart.

### Typed reads

These return the requested type or throw `InvalidArgumentException`:

```php
Cache::string('name');
Cache::integer('count');
Cache::float('ratio');
Cache::boolean('flag');
Cache::array('rows');
```

A missing key with no default reaches the type check as `null` and therefore throws. Pass a default when the key may be absent.

---

## 3. Storing values

```php
Cache::put('key', $value, 600);                              // seconds
Cache::put('key', $value, Date::now()->add_minutes(10));     // a date and time
Cache::put('key', $value, new DateInterval('PT10M'));        // an interval
Cache::put('key', $value);                                   // no expiry

Cache::add('key', $value, 600);        // only when absent; returns false otherwise
Cache::forever('key', $value);
Cache::touch('key', 3600);             // extend an existing entry
Cache::put_many(['a' => 1, 'b' => 2], 600);
```

Because `Somoy` satisfies `DateTimeInterface`, `Date::now()->add_minutes(10)` works anywhere a lifetime is accepted.

**A lifetime of zero or less removes the key** and reports failure, matching Laravel:

```php
Cache::put('key', $value, 0);    // removes 'key', returns false
Cache::put('key', $value, -5);   // same
```

### `add()` is not atomic

Atomic locking is out of scope for this cache, so `add()` is a read followed by a write. Two concurrent requests can both see the key as absent and both write. Do not use it as a mutex.

---

## 4. Retrieve and store

```php
$value = Cache::remember('users', 3600, function () {
    return User::query()->get();
});

$value = Cache::remember_forever('settings', function () {
    return load_settings();
});
```

The callback runs at most once per miss. A callback that returns `null` or `false` is still cached, and will not run again until the entry expires.

---

## 5. Stale while revalidate

`flexible()` serves a slightly stale value rather than making a visitor wait for a recomputation:

```php
$users = Cache::flexible('users', [300, 3600], function () {
    return User::query()->get();
});
```

- Within the first 300 seconds the cached value is returned untouched.
- Between 300 and 3600 seconds the cached value is returned **immediately**, and the refresh is registered to run once the response has been sent.
- After 3600 seconds the value is recomputed before returning.

**How the refresh runs.** The callback is hooked onto `shutdown`. The handler calls `fastcgi_finish_request()` (PHP-FPM) or `litespeed_finish_request()` where available, so the visitor already has their response before the recomputation starts. Where neither exists, the refresh still runs at the end of the request, which is no worse than `remember()` would have been.

**The stampede guard is best effort.** Laravel guards the refresh with an atomic lock. This cache has no locks, so it uses a short-lived marker instead: it collapses most concurrent refreshes but cannot guarantee only one runs. Laravel's `$lock` parameter is deliberately absent from the signature rather than accepted and ignored.

---

## 6. Request-level memoization

`memo()` remembers values already read during the current request, so repeated reads of the same key never reach the store:

```php
Cache::memo()->get('key');   // reads the store
Cache::memo()->get('key');   // returns the memoized value
Cache::memo('file')->get('key');   // memoize over a specific store
```

Misses are memoized too, so a repeatedly absent key is looked up only once.

**Writes that bypass the memoized cache are still observed.** This is a deliberate improvement over Laravel, whose `memo` driver goes stale in this situation:

```php
Cache::memo()->get('name');   // 'taylor'
Cache::put('name', 'tim');    // written through the ordinary cache
Cache::memo()->get('name');   // 'tim' — Laravel would still return 'taylor'
```

---

## 7. Removing values

```php
Cache::forget('key');
Cache::delete_multiple(['a', 'b']);
Cache::flush();                  // every entry in the store
```

### Counters

```php
Cache::increment('hits');
Cache::increment('hits', 5);
Cache::decrement('hits');
```

**The `database` driver is atomic when a persistent object cache is active.** The storage envelope rules out SQL arithmetic and pointing `wp_cache_incr()` straight at it, since the whole serialized entry — not a bare integer — occupies the cache slot. Instead, the counter's authoritative value lives in a separate, envelope-free object cache slot adjusted through the backend's own atomic increment (native `INCR`/`DECR` on Redis or Memcached), and is mirrored back into the envelope afterwards so `get()` and `put()` keep reading correct data. `put()`, `forever()` and `forget()` clear that mirror slot, so the two representations can never drift apart.

**Without a persistent object cache — and always for the `file` driver — these are not atomic.** Both fall back to (or only ever perform) a read-modify-write over the stored envelope; the filesystem abstraction in particular offers no locked read-modify-write. Concurrent adjustments can be lost.

This matters most for the two things people reach for counters to build. A rate limiter built on `Cache::increment()` will undercount under load on a host without an object cache. Where an exact count matters regardless of deployment, use the query builder, which can do it atomically:

```php
DB::table('counters')->where('id', $id)->update(['hits' => DB::raw('hits + 1')]);
```

---

## 8. Multiple stores

```php
Cache::store('file')->get('key');
Cache::store('array')->put('key', $value, 60);
Cache::driver('database');          // alias of store()
```

Calls made directly on the facade go to the default store.

### Registering your own driver

Implement `Framework\Contracts\Store` — ten primitive methods — and register it:

```php
Cache::extend('mongo', function (array $config, string $name) {
    return new MongoStore($config);
});
```

Then name it in configuration as a store's `driver`. Everything at the repository level — `remember`, `flexible`, the typed readers, `ArrayAccess` — works over your store unchanged.

If your store also implements `Framework\Contracts\CacheEntryProvider`, `flexible()` uses a single stored entry; otherwise it falls back to a companion key.

---

## 9. The drivers

### `database` — WordPress transients

The default, because it is the only driver with no environment requirement.

Its significant advantage: on a host with a persistent object cache drop-in (Redis or Memcached — common on managed WordPress hosting) transients bypass the database entirely and are served from memory, with no configuration on your part.

Two consequences worth knowing:

- **Entries are not enumerable** when an object cache is active, because nothing is written to `wp_options`. `flush()` therefore advances a namespace version rather than deleting rows, which is correct in both deployments and takes constant time. The rows a version leaves behind are not deleted synchronously — a `DELETE` that could touch an unbounded number of rows has no place inside the request that called `flush()`. The superseded version is instead queued and reclaimed by the same scheduled sweep that grooms the `file` driver, a bounded number of versions and rows at a time, retried on every run until nothing is left. It runs only while no object cache is active, since a version an object cache is serving has nothing to delete until that cache evicts it. Disable it the same way as the `file` driver's, with `'gc' => false`.
- **Without an object cache, each first read costs two option lookups**, since WordPress stores a transient's value and its expiry as separate rows.

### `file` — the uploads directory

Faster than `database` on hosts with no object cache. Entries live beneath the uploads directory in a directory whose name carries a salted suffix, spread across a two-level fan-out.

Entries are protected from direct web access by four layers: an `index.php`, an `.htaccess` (honoured by Apache, ignored by nginx), a `<?php exit; ?>` guard at the head of every entry file, and unguessable salted filenames — the last being the layer that holds regardless of server configuration.

**It degrades rather than fails.** Where WordPress would select an FTP or SSH transport, or cannot select one at all, the store diverts to the store named by its `fallback` setting (`database` by default), logs a warning, and calls `_doing_it_wrong()` in development. A cache is an optimisation and must never be the reason a site is down.

Expired entries are removed when a read finds them, and by a scheduled sweep that visits a bounded, rotating slice of the cache on each run so it never performs a long scan.

### `array` — memory, for the current request

Honours lifetimes exactly as the persistent drivers do, so a test written against it predicts production behaviour.

---

## 10. Configuration

`config/cache.php` is optional; every key has a documented default and the cache works correctly with the file absent. See `example/config/cache.php` for the annotated version.

```php
return [
    'default' => 'database',
    'stores'  => [
        'database' => [
            'driver'      => 'database',
            'forever_ttl' => 10 * YEAR_IN_SECONDS,
            'network'     => false,
            'gc'          => 'daily',  // false disables the flush cleanup sweep
            'events'      => true,
        ],
        'file' => [
            'driver'   => 'file',
            'path'     => null,        // null derives one beneath uploads
            'fallback' => 'database',
            'gc'       => 'daily',     // false disables the sweep
            'events'   => true,
        ],
        'array' => ['driver' => 'array', 'events' => false],
    ],
];
```

Naming a driver that is not registered raises `InvalidArgumentException` identifying the driver and the store. Nothing is ever substituted silently.

---

## 11. Events

Five events are dispatched through the framework's event system: `CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`, `CacheFlushed`. Each carries the store name and the key; `CacheHit` and `KeyWritten` also carry the value.

The event object is only constructed when something is listening for it, so leaving events on costs a single array lookup per operation. Set `'events' => false` on a store to disable them entirely.

---

## 12. Multisite

Entries are scoped to the site that wrote them. Switching sites during a request — including through `switch_to_blog()` — never surfaces another site's values, memoized ones included.

To share a store across an entire network, set `'network' => true`. Its entries are then written as site transients and are visible on every site. A `flush()` on a network store queues its superseded version for the same scheduled sweep as a site-scoped one; on genuine Multisite that sweep deletes from the network's site meta table, scoped to the current network, since that is where `set_site_transient()` itself writes once a network exists to share entries across.

---

## 13. Where this differs from Laravel

Four deliberate divergences, each forced by WordPress:

**`forever()` uses a long finite lifetime.** WordPress marks a transient with no expiry as an *autoloaded* option, and skips its own 150,000-byte autoload guard when doing so — so a large `forever()` value would be read and unserialized on every request, at a cost paid before your code runs and therefore nearly undiagnosable. The entry never logically expires as far as the cache API is concerned, while the underlying transient is given a ten-year lifetime so WordPress leaves it out of autoload. Set `forever_ttl` to `0` for literal never-expiry. Note that Laravel does not treat `forever` as durable either — its own documentation warns that Memcached may evict such entries.

**`flexible()` refreshes on `shutdown`.** Laravel uses deferred functions and an atomic lock; neither exists here. See [section 5](#5-stale-while-revalidate).

**`increment()` and `decrement()` are only atomic on the `database` driver with a persistent object cache active.** See [section 7](#7-removing-values).

**`flush()` advances a namespace version.** Necessary because transients cannot be enumerated under an object cache. The practical effect is the same — every prior key reads as a miss — and it takes constant time. Reclaiming the rows a version leaves behind happens later, off the request that called `flush()`, in the same scheduled sweep described in [section 9](#9-the-drivers).

Also absent by design: `Cache::lock()` and everything derived from it (`funnel`, `without_overlapping`, `flush_locks`), tagged cache (`supports_tags()` returns `false`), the `failover` driver, `sear`, and `rememberWithWarmth`.

---

## 14. CLI reference

```bash
wp cache:clear
wp cache:clear --store=file
wp cache:forget my-key
wp cache:forget my-key --store=file
wp cache:gc
```

Keys are stored under a derived identifier rather than their literal name, so `wp transient delete` cannot target them. `cache:forget` is the supported way to invalidate a single entry from outside the application.
