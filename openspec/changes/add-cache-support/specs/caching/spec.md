## Purpose

Stores and retrieves values under a key with a time-to-live, across swappable storage drivers,
so that expensive work is computed once and reused. Provides a single correct implementation of
caching on WordPress, with uniform behaviour across drivers and explicitly documented limits
where the platform prevents a guarantee.

## ADDED Requirements

### Requirement: Cache access surface

The framework SHALL expose caching through a `Cache` facade and a `cache()` helper. Method names
SHALL use the framework's snake_case convention while preserving the verbs of the reference
Laravel API, so that `rememberForever` becomes `remember_forever` and `putMany` becomes
`put_many`.

The helper SHALL be overloaded by argument type: no arguments returns the cache manager, a
string key reads with an optional default, and an array of key/value pairs writes with the
second argument as the time-to-live.

#### Scenario: Reading through the facade

- **WHEN** code calls `Cache::get('settings')` for a key that was previously stored and has not expired
- **THEN** the stored value is returned

#### Scenario: Helper with no arguments

- **WHEN** code calls `cache()` with no arguments
- **THEN** the cache manager is returned, so that any cache method may be called on it

#### Scenario: Helper writing many values

- **WHEN** code calls `cache(['a' => 1, 'b' => 2], 600)`
- **THEN** both keys are stored with a 600-second lifetime and a boolean success result is returned

### Requirement: Value fidelity

A cached value SHALL be returned exactly as it was stored, including values that are falsy. A
missing key SHALL be distinguishable from a key whose stored value is `false`, `null`, `0`, or
the empty string.

#### Scenario: Caching a false value

- **WHEN** `false` is stored under a key and that key is then read
- **THEN** `false` is returned, `has()` reports the key as present, and `missing()` reports it as absent from the miss case

#### Scenario: Remembering a falsy computation

- **WHEN** a `remember()` callback returns `null` and the same `remember()` call is made again within the lifetime
- **THEN** the callback is not invoked a second time and the cached `null` is returned

#### Scenario: Reading an absent key

- **WHEN** a key that was never stored is read
- **THEN** the supplied default is returned, or `null` when no default was supplied

### Requirement: Time-to-live semantics

A time-to-live SHALL be accepted as an integer number of seconds, a date-time value, a date
interval, or `null`. A `null` lifetime SHALL mean the entry does not expire.

A lifetime that is zero, negative, or a date-time already in the past SHALL remove the key
rather than store it, and the write SHALL report failure.

#### Scenario: Absolute expiry

- **WHEN** a value is stored with a date-time ten minutes in the future
- **THEN** the value is readable before that moment and is a miss afterwards

#### Scenario: Non-positive lifetime removes

- **WHEN** a value is stored under an existing key with a lifetime of zero or a negative number of seconds
- **THEN** the key is removed and the write reports failure

#### Scenario: Default lifetime closure

- **WHEN** a key is read with a closure as its default and the key is absent
- **THEN** the closure is evaluated and its result returned; when the key is present the closure is not evaluated

### Requirement: Retrieve and store

The cache SHALL provide `remember()` and `remember_forever()`, which return a cached value when
present and otherwise invoke a callback, store its result, and return it. The callback SHALL be
invoked at most once per cache miss.

#### Scenario: Populating on miss

- **WHEN** `remember()` is called for an absent key
- **THEN** the callback is invoked once, its result is stored with the given lifetime, and that result is returned

### Requirement: Stale-while-revalidate

The cache SHALL provide `flexible()`, taking a fresh duration and a stale duration. Inside the
fresh window a cached value SHALL be returned unchanged. Inside the stale window the cached
value SHALL be returned immediately and a refresh SHALL be scheduled to run after the HTTP
response has been sent where the server supports that, and otherwise at the end of the request.
Past the stale window the value SHALL be recomputed before returning.

Because atomic locking is out of scope, the refresh guard SHALL be best effort: concurrent
requests inside the stale window MAY each trigger a refresh. This limitation SHALL be documented
on the method.

#### Scenario: Serving a stale value

- **WHEN** a `flexible()` key is read after its fresh window but before its stale window ends
- **THEN** the cached value is returned without recomputation and a refresh is registered to run after the response

#### Scenario: Expired beyond the stale window

- **WHEN** a `flexible()` key is read after its stale window has ended
- **THEN** the callback is invoked and its result is stored and returned before the response

### Requirement: Named stores and driver extension

The cache SHALL support multiple named stores selected by configuration, reachable with
`store()` and `driver()`. Third parties SHALL be able to register additional drivers with
`extend()` by supplying an implementation of the store contract, which SHALL consist only of the
primitive operations: read one, read many, write one, write many, increment, decrement, write
without expiry, forget, flush, and read the key prefix.

#### Scenario: Selecting a store

- **WHEN** code calls `Cache::store('array')->put('k', 'v', 60)` while the default store is `database`
- **THEN** the value is written to the array store only, and the default store does not contain the key

#### Scenario: Registering a custom driver

- **WHEN** a custom driver is registered with `extend()` and named as a store's driver in configuration
- **THEN** resolving that store returns a repository backed by the custom implementation, and all repository-level verbs work against it unchanged

#### Scenario: Unknown driver

- **WHEN** a store is configured with a driver name that is not registered
- **THEN** resolving that store fails with an error naming the offending driver and configuration key

### Requirement: Request-level memoization

The cache SHALL provide `memo()`, returning a cache that remembers values already read during
the current request so that repeated reads of the same key do not reach the underlying store.
Misses SHALL be memoized as well as hits.

A write performed through any cache instance for the same store SHALL invalidate the memoized
value for that key, so that a memoized read never returns a value that a later write in the same
request has replaced.

#### Scenario: Repeat reads

- **WHEN** the same key is read twice through `memo()`
- **THEN** the underlying store is consulted only on the first read

#### Scenario: Write bypassing the memoized cache

- **WHEN** a key is read through `memo()`, then written through the ordinary cache, then read through `memo()` again
- **THEN** the second memoized read returns the newly written value, not the memoized one

### Requirement: Key handling

A cache key SHALL be accepted regardless of its length or the characters it contains, and SHALL
never collide with a different key. A key that cannot be stored verbatim by the underlying
storage SHALL be transformed rather than rejected or silently truncated.

Where a transformation is used, the cache SHALL expose the means to compute a stored key's
identifier, so that an entry can be located from outside the running application.

#### Scenario: Very long key

- **WHEN** a value is stored under a key longer than the underlying storage permits, and a second value is stored under a different key sharing a long common prefix with the first
- **THEN** both values are stored independently and each reads back its own value

#### Scenario: Key with path-unsafe characters

- **WHEN** a key containing `/` or `..` is used with any driver
- **THEN** the value round-trips correctly and no storage location outside the cache's own area is read or written

### Requirement: Flushing

The cache SHALL provide `flush()`, after which every key previously written to that store reads
as a miss. Flushing SHALL be correct regardless of whether the underlying storage can be
enumerated.

#### Scenario: Flush hides all prior entries

- **WHEN** several keys, including one written without an expiry, are stored and `flush()` is then called on that store
- **THEN** every one of those keys reads as a miss

#### Scenario: Flush with a persistent object cache active

- **WHEN** `flush()` is called on the database store on a site running a persistent object cache
- **THEN** previously written keys read as misses, and the operation does not report success without having taken effect

### Requirement: Database driver

The `database` driver SHALL store entries using the WordPress transient API, so that sites
running a persistent object cache benefit from it without configuration.

An entry written without an expiry SHALL NOT be stored in a form that WordPress loads
automatically on every request. The lifetime used for such entries SHALL be configurable,
including a setting that restores literal never-expiry.

#### Scenario: Storing without an expiry

- **WHEN** `forever()` stores a value through the database driver
- **THEN** the value reads back correctly, and no option is created that WordPress autoloads

#### Scenario: Object cache present

- **WHEN** the site runs a persistent object cache drop-in
- **THEN** reads and writes through the database driver still behave identically to a site without one

### Requirement: File driver

The `file` driver SHALL store entries beneath the WordPress uploads directory, at a location
overridable by configuration.

Stored entries SHALL NOT be readable over HTTP by a party who does not already know the site's
secret salts, on any server configuration.

Where the WordPress filesystem abstraction is unavailable or would perform remote transfers, the
file driver SHALL fall back to another configured store rather than fail, and SHALL record a
warning. A cache SHALL never be the cause of a failed request.

Expired entries SHALL be removed on read, and additionally by a scheduled sweep that performs a
bounded amount of work per run.

#### Scenario: Direct HTTP request for a cache entry

- **WHEN** a cache entry's storage location is requested over HTTP
- **THEN** no part of the stored value is disclosed

#### Scenario: Filesystem unavailable

- **WHEN** the file driver is selected on a host where the WordPress filesystem cannot be used directly
- **THEN** cache operations succeed against the configured fallback store, a warning is recorded, and no error is surfaced to the visitor

#### Scenario: Scheduled sweep

- **WHEN** the scheduled sweep runs against a cache directory containing many entries
- **THEN** expired entries are progressively removed and no single run scans the entire directory

### Requirement: Array driver

The `array` driver SHALL store entries in memory for the duration of the current request only,
and SHALL honour time-to-live semantics identically to the persistent drivers.

#### Scenario: Expiry within a request

- **WHEN** a value is stored in the array store with a lifetime that then elapses
- **THEN** the key reads as a miss, matching the behaviour of the persistent drivers

### Requirement: Multisite scoping

On a WordPress network, cache entries SHALL be scoped to the site that wrote them. Switching the
active site during a request SHALL NOT expose another site's cached values, including values
already read earlier in the same request.

A store MAY be configured as network-wide, in which case its entries SHALL be shared across all
sites in the network.

#### Scenario: Switching sites mid-request

- **WHEN** a key is read on one site, the active site is switched, and the same key is read again
- **THEN** the second read reflects the second site's cache, not the first site's value

#### Scenario: Network-wide store

- **WHEN** a store configured as network-wide is written on one site and read on another
- **THEN** the value is visible on both

### Requirement: Counters

The cache SHALL provide `increment()` and `decrement()`, returning the resulting value, or
failure when the stored value is not numeric.

These operations SHALL NOT be represented as atomic. Concurrent adjustments MAY be lost. This
limitation SHALL be documented, together with a pointer to a storage mechanism that does offer
atomic adjustment.

#### Scenario: Adjusting a counter

- **WHEN** `increment()` is called on a key holding an integer
- **THEN** the stored value is raised by the given amount and the new value is returned

#### Scenario: Adjusting a non-numeric value

- **WHEN** `increment()` is called on a key holding a string
- **THEN** the operation reports failure and the stored value is unchanged

### Requirement: Cache events

The cache SHALL emit events for a hit, a miss, a key being written, a key being forgotten, and a
store being flushed, through the framework's event system.

Emitting an event SHALL impose no measurable cost when nothing is listening for it. Events SHALL
be disableable per store by configuration.

#### Scenario: Listening for misses

- **WHEN** a listener is registered for the cache-miss event and an absent key is read
- **THEN** the listener receives the event with the store name and the key

#### Scenario: Events disabled

- **WHEN** a store is configured with events disabled and any cache operation is performed
- **THEN** no cache event is emitted for that store

### Requirement: Configuration

The cache SHALL be fully functional with no configuration file present, using documented
defaults. A configuration file SHALL act purely as an override surface, with every key optional.

The default store SHALL be one that works on every WordPress host without additional
requirements.

#### Scenario: No configuration file

- **WHEN** an application with no cache configuration file performs a cache operation
- **THEN** the operation succeeds against the default store

### Requirement: Command-line management

The framework SHALL provide commands to flush a store, to forget a single key, and to run the
file driver's sweep on demand. Each SHALL accept an optional store name and default to the
configured default store.

#### Scenario: Forgetting one key from the command line

- **WHEN** the forget command is run with a key that exists in the cache
- **THEN** that key subsequently reads as a miss and other keys are unaffected
