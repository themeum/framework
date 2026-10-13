## Purpose

Defines how the persistent cache drivers store and restore values without PHP object
deserialization, so that a tampered cache entry cannot create an arbitrary object and the library
passes the WordPress.org review rule on `unserialize()`.

## ADDED Requirements

### Requirement: Persistent drivers store no PHP-serialized data
The `file` and `database` drivers SHALL store every entry as JSON. The library MUST NOT call PHP
deserialization functions on cache data in any form other than with object creation disabled.
For the `database` driver, the value handed to the WordPress transient API SHALL be a plain
string, so that WordPress never unserializes an object from it.

#### Scenario: File entry contents
- **WHEN** a value is stored through the file driver
- **THEN** the entry file contains the execution guard followed by a JSON document, and no PHP-serialized data

#### Scenario: Transient contents
- **WHEN** a value is stored through the database driver
- **THEN** the transient's stored value is a string holding a JSON document

### Requirement: Value fidelity through the encoding
A value read back from a persistent driver SHALL equal the value that was stored, with the same
types. This SHALL hold for integers, floats including non-finite ones, booleans, `null`, UTF-8
and binary strings, lists, keyed arrays including numeric-string and reserved-looking keys, and
objects of allowed classes nested anywhere in the value. A stored `false`, `null`, `0` or empty
string SHALL remain distinguishable from a miss.

#### Scenario: Scalar and array round trip
- **WHEN** a nested array holding every scalar type, a binary string and `INF` is stored and read back through either persistent driver
- **THEN** the value read back is identical to the one stored

#### Scenario: Model round trip
- **WHEN** a model with loaded attributes and a loaded relation is stored and read back
- **THEN** the value read back is a model of the same class with the same attributes, original attributes and relation

#### Scenario: Date round trip
- **WHEN** a `DateTime`, `DateTimeImmutable` or Somoy date is stored and read back
- **THEN** the value read back has the same class, the same instant and the same time zone

#### Scenario: Stored false
- **WHEN** `false` is stored under a key and that key is read
- **THEN** `false` is returned and the key is reported as present

### Requirement: Only allowed classes are restored
The persistent drivers SHALL restore an object only if its class is in the built-in set or is
listed in the cache's `allowed_classes` configuration. The built-in set SHALL be `stdClass`,
`DateTime`, `DateTimeImmutable`, `DateInterval`, Somoy dates, models, and the framework's
collection classes. The allowlist MUST be taken from configuration and never from stored data.

#### Scenario: Configured value object
- **WHEN** a class is listed in `cache.allowed_classes` and an instance of it is stored and read back
- **THEN** the instance read back has the same class and property values, and its constructor did not run

#### Scenario: No configuration
- **WHEN** the cache configuration has no `allowed_classes` key
- **THEN** only the built-in set is allowed, and caching works as it does today for scalars, arrays and built-in types

### Requirement: Disallowed values fail on write
Storing a value through a persistent driver that holds an object of a class that is not allowed,
a closure, or a resource SHALL throw an exception. The exception SHALL name the cache key, the
location of the value within it, and the offending type. Nothing SHALL be written, and any entry
already stored under that key SHALL be left unchanged.

#### Scenario: WordPress object
- **WHEN** a `WP_Post` is passed to `put()` on the file or database store
- **THEN** an exception is thrown naming the key and `WP_Post`, and the key's previous entry, if any, is still readable

#### Scenario: Remember with a disallowed result
- **WHEN** a `remember()` callback returns an object of a class that is not allowed
- **THEN** the exception propagates to the caller and nothing is cached

#### Scenario: Array driver
- **WHEN** an object of any class is stored through the array driver
- **THEN** it is stored and returned as before, because the array driver keeps values in memory and never encodes them

### Requirement: Untrusted or unreadable entries read as misses
An entry that is not valid JSON, that has an unknown envelope format, that belongs to a different
key, that is structurally malformed, or that names a class that is not allowed SHALL be treated
as a miss. No object of a class that is not allowed SHALL be created while reading it, and no
exception SHALL reach the caller. The class check SHALL happen before any object is created.

#### Scenario: Tampered file naming a dangerous class
- **WHEN** an entry file is rewritten so that its value names a class that is not allowed and that has side effects on creation or destruction
- **THEN** the read returns the default value, the class is never instantiated, and none of its magic methods run

#### Scenario: Corrupt entry
- **WHEN** an entry file or transient holds bytes that are not valid JSON
- **THEN** the read returns the default value without raising

#### Scenario: Garbage collection of unreadable files
- **WHEN** the scheduled sweep reaches an entry file that cannot be decoded
- **THEN** the file is removed

### Requirement: Entries from earlier versions read as misses
Entries written by earlier versions of the library in PHP-serialized form SHALL be treated as
misses, with no migration step. They SHALL be replaced the next time the key is written. Old
file entries SHALL be removed by the scheduled sweep.

#### Scenario: Old file entry
- **WHEN** an entry file written in the earlier serialized format is read
- **THEN** the read is a miss and no object is created from the file's contents

#### Scenario: Old transient
- **WHEN** a transient written in the earlier array form is read through the database driver
- **THEN** the read is a miss, and the next write replaces the transient with the JSON form
