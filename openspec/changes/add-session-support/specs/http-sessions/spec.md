## Purpose

Provides state that survives across requests for both anonymous visitors and logged-in users: reading and writing session values, flashing values that live for exactly one following request, and persisting that state without introducing a database table or a garbage-collection schedule.

## ADDED Requirements

### Requirement: Sessions start lazily

A session MUST NOT be started merely because a request arrived. The framework SHALL start a session only when the application first writes to it, or explicitly asks for a value that requires an identity to exist (the session identifier or the parity token).

Until a session starts, reads MUST return the caller's supplied default without error, no session identifier cookie may be emitted, and nothing may be written to the storage driver.

This deviates deliberately from frameworks that start a session on every request: in WordPress, emitting a cookie for every anonymous visitor disables full-page caching for the whole site.

#### Scenario: Request that only reads

- **WHEN** a request reads a session value and never writes one
- **AND** the request carried no session identifier cookie
- **THEN** the read MUST return the supplied default
- **AND** no session identifier cookie may be emitted
- **AND** nothing may be written to the storage driver

#### Scenario: Request that writes

- **WHEN** a request writes a session value for the first time
- **THEN** a session identifier MUST be generated
- **AND** a session identifier cookie MUST be queued for emission
- **AND** the session payload MUST be persisted when the request is saved

#### Scenario: Request carrying an existing session

- **WHEN** a request arrives with a valid session identifier cookie
- **AND** the application reads a value that was stored on an earlier request
- **THEN** the stored value MUST be returned

### Requirement: Session identifiers are unguessable and validated before use

Session identifiers SHALL be generated from a cryptographically secure source and MUST consist of exactly 40 lowercase hexadecimal characters.

An identifier arriving from the client MUST be rejected unless it matches that format exactly. A rejected identifier MUST be discarded and treated as though no session cookie were present; it MUST NOT be used to build a storage key, and it MUST NOT cause an error visible to the visitor.

#### Scenario: Malformed identifier from the client

- **WHEN** a request arrives with a session cookie whose value is not 40 hexadecimal characters
- **THEN** the value MUST be discarded
- **AND** the request MUST behave as though no session cookie were present
- **AND** no storage lookup may be performed with the supplied value

#### Scenario: Identifier containing storage-key metacharacters

- **WHEN** a request arrives with a session cookie value containing characters outside the hexadecimal range
- **THEN** those characters MUST NOT reach the storage layer in any form

### Requirement: The session identifier cookie

The session identifier cookie SHALL be hidden from client-side scripts, restricted to secure connections when the current request is served over TLS, and default to a same-site policy of `Lax`. Its name, path, domain, secure flag, same-site policy, and lifetime MUST be configurable by the host application, with framework defaults applied when configuration is absent.

The cookie MUST be re-queued only on requests where the session was both started and modified, so that a request which merely reads an existing session adds no `Set-Cookie` header.

When the host application configures the session to expire on browser close, the cookie MUST be emitted as a browser-session cookie with no expiry time.

#### Scenario: Cookie attributes

- **WHEN** a session identifier cookie is emitted
- **THEN** it MUST be marked as hidden from client-side scripts
- **AND** its same-site policy MUST default to `Lax`
- **AND** it MUST be marked secure when the current request is served over TLS

#### Scenario: Read-only request on an existing session

- **WHEN** a request carries a valid session cookie
- **AND** the request reads session values but modifies none
- **THEN** no session identifier cookie may be re-emitted

#### Scenario: Expire on browser close

- **WHEN** the host application configures the session to expire on browser close
- **AND** a session identifier cookie is emitted
- **THEN** the cookie MUST carry no expiry time

### Requirement: Session persistence and expiry

A started session MUST be persisted at the point the framework sends a response, and MUST also be persisted for request paths that end without passing through the framework's response senders.

A save MUST be idempotent: saving a session that was never started, or one that has not been modified since it was loaded, MUST NOT write to the storage driver.

The stored payload's lifetime SHALL be reset to the configured session lifetime on every save, so an actively used session does not expire mid-use. Expiry MUST be handled by the storage layer itself; the framework MUST NOT introduce a scheduled cleanup task or a dedicated database table.

#### Scenario: Save after a write

- **WHEN** a request writes a session value and a response is sent
- **THEN** the session payload MUST be persisted before the response completes

#### Scenario: Save with no modification

- **WHEN** a session is loaded from storage but no value is written, removed, or flashed
- **AND** the session is saved
- **THEN** no write may be issued to the storage driver

#### Scenario: Save without a start

- **WHEN** a session was never started and the session is saved
- **THEN** no write may be issued to the storage driver

#### Scenario: Redirect responses

- **WHEN** a request writes a session value and returns a redirect
- **THEN** the session MUST be persisted before the redirect is issued and the request terminates

#### Scenario: Sliding expiry

- **WHEN** an existing session is saved
- **THEN** the stored payload's remaining lifetime MUST be reset to the full configured session lifetime

### Requirement: Sessions holding no application data are pruned

A session whose payload contains only internal bookkeeping — the parity token, empty flash bookkeeping, and any recorded previous URL — holds nothing the application put there. On save, such a session MUST NOT be persisted: any existing stored payload MUST be destroyed, and the identifier cookie MUST be expired rather than re-queued.

This keeps a visitor who merely tripped a validation error from remaining uncacheable for the full session lifetime, and stops drive-by traffic from leaving stored payloads behind once their flash data has been consumed.

#### Scenario: Flash consumed and nothing else stored

- **WHEN** a session's only content was flash data
- **AND** that flash data is aged out on a later request, leaving only bookkeeping
- **THEN** the stored payload MUST be destroyed
- **AND** the identifier cookie MUST be expired
- **AND** no new payload may be written

#### Scenario: Application data keeps the session alive

- **WHEN** a session holds at least one value written by the application
- **THEN** it MUST be persisted normally, regardless of the bookkeeping it also carries

#### Scenario: Parity token alone does not sustain a session

- **WHEN** a session's only content is the parity token
- **THEN** it MUST be treated as holding no application data and pruned

### Requirement: Writes after response headers are already sent

If a session is first started after the response headers have already been sent, its identifier cookie can never reach the client, and the payload would be unreadable on any later request.

In that situation the framework SHALL distinguish two cases:

- If the session identifier came from the request's own cookie, the client already holds it, so the payload MUST be persisted normally.
- If the identifier was generated during this request, the payload MUST NOT be persisted, and a warning naming the condition MUST be logged.

Neither case may raise an error to the visitor.

#### Scenario: Late write on an existing session

- **WHEN** a request carries a valid session cookie
- **AND** the first session write happens after response headers were sent
- **THEN** the payload MUST be persisted
- **AND** no warning is required

#### Scenario: Late first write on a new session

- **WHEN** a request carries no session cookie
- **AND** the first session write happens after response headers were sent
- **THEN** the payload MUST NOT be persisted
- **AND** a warning MUST be logged describing why the session could not be established
- **AND** the request MUST continue without an error visible to the visitor

### Requirement: Reading and writing session values

The session service SHALL provide, using the framework's naming conventions, the ability to: read a value with a default, read every value, read a named subset, test for a key's presence, test for a key's presence including null values, write one key or many keys at once, append to an array-valued key, read-and-remove a key, increment and decrement numeric keys, read a key or compute and store it when absent, remove one or many keys, and remove every key.

Key reads and writes MUST support dot notation for nested values, consistent with the framework's existing array access helpers.

#### Scenario: Write then read

- **WHEN** a value is written under a key and later read
- **THEN** the stored value MUST be returned

#### Scenario: Read a missing key

- **WHEN** a key that was never written is read with a default
- **THEN** the default MUST be returned

#### Scenario: Read and remove

- **WHEN** a key is read-and-removed
- **THEN** its value MUST be returned
- **AND** the key MUST no longer be present in the session

#### Scenario: Presence versus existence

- **WHEN** a key holds a null value
- **THEN** the presence test MUST report false
- **AND** the existence test MUST report true

#### Scenario: Nested value access

- **WHEN** a value is written under a dotted key
- **THEN** reading that dotted key MUST return the value

### Requirement: Flash data lives for exactly one following request

The framework SHALL provide flash data: values written for the next request only, plus the ability to make a value available for the current request only, to extend all flashed values by one more request, and to extend named flashed values by one more request.

Flashed values MUST be aged at save time: values flashed during this request become readable on the next request, and values that were readable on this request MUST be removed. A value extended during the request MUST survive that ageing.

#### Scenario: Flash then read on the next request

- **WHEN** a value is flashed during request A
- **THEN** it MUST be readable during the immediately following request B
- **AND** it MUST NOT be readable during request C

#### Scenario: Flash for the current request only

- **WHEN** a value is flashed for the current request only
- **THEN** it MUST be readable immediately
- **AND** it MUST NOT be readable on the following request

#### Scenario: Extending all flashed values

- **WHEN** flashed values readable during request B are extended
- **THEN** they MUST also be readable during request C

#### Scenario: Extending named flashed values

- **WHEN** one named flashed value readable during request B is extended
- **THEN** that value MUST be readable during request C
- **AND** the other flashed values MUST NOT be

### Requirement: Redirects can carry flash data, old input, and errors

A redirect response SHALL be able to attach arbitrary flash values, the current request's input as old input, and a set of validation errors, in a fluent style consistent with how the framework already attaches cookies to responses.

Old input MUST exclude sensitive and non-serializable fields before anything is written. At minimum, fields named `password`, `password_confirmation`, and `current_password` MUST never be flashed, and uploaded files MUST never be flashed. The excluded field list MUST be extendable by the host application through configuration.

This exclusion is load-bearing: the framework has no encrypter, so a flashed password would be written to the storage driver in plaintext, readable by any other plugin, any database backup, and any operator, for the full session lifetime.

After such a redirect, the following request MUST be able to read individual old-input values with a default, and to read the errors through a read-only accessor exposing at least: whether any error exists, whether a named field has an error, the first message for a named field, all messages for a named field, and every message.

Errors MUST be stored as plain data. No framework object may be written into the storage driver, so that renaming or moving a class cannot break sessions already in flight.

#### Scenario: Redirect with flash data

- **WHEN** a redirect attaches a flash value and the visitor follows it
- **THEN** the value MUST be readable on the request that follows the redirect

#### Scenario: Redirect with old input

- **WHEN** a redirect attaches the current request's input as old input
- **THEN** each submitted field MUST be readable by name on the following request
- **AND** a field that was not submitted MUST return the supplied default

#### Scenario: Redirect with errors

- **WHEN** a redirect attaches validation errors and the visitor follows it
- **THEN** the error accessor MUST report that errors exist
- **AND** the first message for a named field MUST be readable
- **AND** a field without errors MUST report no error

#### Scenario: Sensitive fields are never flashed

- **WHEN** a request submitting a password field is redirected with its input attached
- **THEN** the password field MUST NOT appear in the flashed old input
- **AND** it MUST NOT appear in the payload written to the storage driver

#### Scenario: Uploaded files are never flashed

- **WHEN** a request carrying an uploaded file is redirected with its input attached
- **THEN** no file object may be written to the storage driver

#### Scenario: Errors are stored as plain data

- **WHEN** errors are flashed to the session
- **THEN** the persisted payload MUST contain only plain arrays, strings, and scalars for those errors

### Requirement: Session identity lifecycle

The framework SHALL provide three identity operations: regenerating the identifier while keeping the data, discarding the data and the identifier together, and migrating to a new identifier with an explicit choice of whether the previous stored payload is destroyed.

When an identifier is replaced, the parity token MUST be regenerated with it.

The framework SHALL by default regenerate the identifier while preserving data when a user logs in to WordPress, and discard the session when a user logs out. Both MUST do nothing when no session was ever started, so the lazy behaviour is preserved for sites that never use sessions.

#### Scenario: Regenerating the identifier

- **WHEN** a session holding data has its identifier regenerated
- **THEN** the identifier MUST change
- **AND** the stored data MUST remain readable

#### Scenario: Invalidating a session

- **WHEN** a session is invalidated
- **THEN** the identifier MUST change
- **AND** every previously stored value MUST be gone

#### Scenario: Login

- **WHEN** a visitor with an active session logs in to WordPress
- **THEN** the session identifier MUST change
- **AND** the session data MUST be preserved

#### Scenario: Logout

- **WHEN** a visitor with an active session logs out of WordPress
- **THEN** the session data MUST be discarded

#### Scenario: Login without a session

- **WHEN** a visitor with no session logs in to WordPress
- **THEN** no session may be created and no cookie emitted

### Requirement: Storage drivers

Persistence SHALL be delegated to a swappable driver selected by host configuration, behind a contract of reading a payload by identifier, writing a payload with a lifetime, and destroying a payload.

Two drivers MUST be provided:

- A persistent driver storing payloads in WordPress transients, so that expiry is handled by WordPress and no dedicated table or scheduled cleanup is introduced. Storage keys MUST be namespaced by the application prefix.
- An in-memory driver that retains values only for the current request and persists nothing, for tests and for contexts where persistence is unwanted.

Driver selection MUST come from configuration alone. The framework MUST NOT silently substitute one driver for another.

#### Scenario: Persistent driver round trip

- **WHEN** a payload is written under an identifier by the persistent driver
- **AND** it is read back under the same identifier
- **THEN** the original values MUST be returned

#### Scenario: Persistent driver expiry

- **WHEN** a payload is written by the persistent driver
- **THEN** it MUST be stored with the configured session lifetime as its expiry
- **AND** no dedicated table or scheduled cleanup task may be created

#### Scenario: In-memory driver does not support redirect-back

- **WHEN** the in-memory driver is selected
- **AND** errors are flashed and a redirect is issued
- **THEN** the errors MUST NOT be readable after the redirect, because nothing was persisted
- **AND** this limitation MUST be documented, since the redirect itself still succeeds and reports no failure

#### Scenario: In-memory driver persists nothing

- **WHEN** the in-memory driver is selected and a value is written
- **THEN** the value MUST be readable within the same request
- **AND** it MUST NOT be readable on a subsequent request

#### Scenario: No silent driver substitution

- **WHEN** the configured driver is the persistent one
- **THEN** the framework MUST NOT fall back to the in-memory driver

### Requirement: Session state is isolated from request input

Session values MUST NOT enter the request's input attributes. They MUST be absent from the collection of all input, from the typed input accessors, from the magic property accessor, and from validation and sanitization input.

A client-supplied value can therefore never satisfy or override a validated input by way of the session, mirroring the isolation already required of request cookies.

#### Scenario: Session values absent from input

- **WHEN** a session holds a value under a key
- **AND** the request's full input collection is read
- **THEN** that key MUST NOT appear in the input

#### Scenario: Session values absent from validation

- **WHEN** a validation rule names a key that exists only in the session
- **THEN** validation MUST treat that key as absent from the input

### Requirement: Resolving the previous page

The framework SHALL provide a way to redirect a visitor back to the page they came from, resolved in order from the WordPress referer, then from a previously recorded session URL when a session was already started, then from a caller-supplied fallback, and finally from the site home URL.

Resolving the previous page MUST NOT itself start a session, so it stays usable on requests that must remain cacheable.

#### Scenario: Referer available

- **WHEN** the previous page is resolved for a request carrying a valid same-host referer
- **THEN** that referer MUST be used as the redirect target

#### Scenario: No referer and no session

- **WHEN** the previous page is resolved for a request with no referer and no session
- **THEN** the caller-supplied fallback MUST be used, or the site home URL when none was supplied

#### Scenario: Resolving the previous page does not start a session

- **WHEN** the previous page is resolved for a visitor with no session
- **THEN** no session may be started and no cookie emitted

### Requirement: Documented limits of session state

The framework SHALL document, and MUST NOT silently work around, the following limits:

- Session payloads are **not encrypted**. Anything stored is readable by anyone with database or object-cache access.
- Concurrent requests that read, modify, and write the same session are **last-write-wins**; no locking is provided.
- On a multisite network, sessions are scoped **per site**, not shared across the network.
- When an external object cache is installed, payloads live in that cache rather than the database, so a cache flush discards live sessions. Session state is cache-durable, not guaranteed-durable, and nothing security-critical may depend on it alone.
- A parity token is provided, but the framework enforces **no** cross-site request forgery check on it. WordPress nonces remain the documented defence.

#### Scenario: Object cache in use

- **WHEN** the persistent driver is selected and an external object cache is active
- **THEN** the framework MUST record a notice that session durability depends on that cache
- **AND** it MUST NOT bypass or reconfigure the object cache to force database storage

#### Scenario: Parity token is not enforced

- **WHEN** a request is submitted without a matching parity token
- **THEN** the framework MUST NOT reject the request on that basis
