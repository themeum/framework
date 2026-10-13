## Purpose

Provides a first-class way for framework applications to create, queue, and emit HTTP cookies, to read incoming request cookies without mixing them into request input, and to send cookies on outbound HTTP client calls.

## ADDED Requirements

### Requirement: Cookie creation with resolved defaults

The framework SHALL provide a cookie factory that builds a cookie from a name, value, and lifetime expressed in minutes, together with optional path, domain, secure, http-only, raw, and same-site attributes.

Any attribute not supplied per-cookie MUST be resolved from configuration when the host application provides it, then from the WordPress environment (installation cookie path, cookie domain, and whether the current request is over TLS), and finally from framework defaults of path `/`, no domain, non-secure, http-only enabled, and same-site `Lax`.

A lifetime of zero minutes MUST produce a session cookie that expires when the browser closes.

#### Scenario: Cookie built with an explicit lifetime

- **WHEN** a cookie is created with a name, a value, and a lifetime of 60 minutes
- **THEN** the resulting cookie MUST carry that name and value
- **AND** its expiry MUST be approximately 60 minutes after the current time

#### Scenario: Unspecified attributes fall back to environment defaults

- **WHEN** a cookie is created without a path, domain, or secure flag
- **AND** the WordPress environment defines an installation cookie path and cookie domain
- **THEN** the cookie MUST adopt the environment's path and domain
- **AND** the secure flag MUST reflect whether the current request is served over TLS

#### Scenario: Host configuration overrides environment defaults

- **WHEN** the host application supplies cookie defaults through configuration
- **AND** a cookie is created without those attributes
- **THEN** the configured values MUST take precedence over the WordPress environment values

#### Scenario: Zero lifetime produces a session cookie

- **WHEN** a cookie is created with a lifetime of zero minutes
- **THEN** the emitted cookie MUST have no expiry time, making it a session cookie

### Requirement: Long-lived and expiring cookies

The framework SHALL provide a way to create a long-lived cookie without stating a lifetime, and a way to create a cookie that instructs the browser to delete an existing cookie of the same name.

A deletion cookie MUST carry an empty value and an expiry in the past, and MUST reuse the same path and domain as the cookie it is deleting so the browser matches it.

#### Scenario: Long-lived cookie

- **WHEN** a long-lived cookie is created
- **THEN** its expiry MUST be several years in the future

#### Scenario: Deleting a cookie

- **WHEN** a deletion cookie is created for an existing cookie name
- **THEN** the cookie MUST carry an empty value
- **AND** its expiry MUST be in the past
- **AND** it MUST carry the same path and domain as the cookie being removed

### Requirement: Same-site attribute handling

The framework SHALL normalize the same-site attribute to one of `Lax`, `Strict`, or `None` regardless of the casing supplied.

When the same-site attribute is `None`, the cookie MUST also be marked secure, because browsers reject a non-secure `SameSite=None` cookie.

#### Scenario: Same-site value is normalized

- **WHEN** a cookie is created with a same-site value of `strict` in any casing
- **THEN** the emitted cookie MUST declare `SameSite=Strict`

#### Scenario: SameSite=None forces the secure flag

- **WHEN** a cookie is created with same-site `None` and the secure flag left off
- **THEN** the emitted cookie MUST be marked secure

### Requirement: Cookie queue

The framework SHALL maintain a queue of cookies awaiting emission, so that any code with access to the cookie service — including services and middleware that do not build the response — can schedule a cookie.

The queue MUST support adding a cookie, removing a queued cookie by name, checking whether a name is queued, retrieving a queued cookie, and listing all queued cookies. Cookies MUST be tracked by name and path together, so the same name queued for two different paths does not overwrite itself.

Queueing a cookie MUST NOT emit it; emission happens only when the queue is flushed.

#### Scenario: Queue and retrieve

- **WHEN** a cookie is queued
- **THEN** the queue MUST report that name as queued
- **AND** the queued cookie MUST be retrievable by name

#### Scenario: Removing a queued cookie

- **WHEN** a queued cookie is removed by name before the queue is flushed
- **THEN** the queue MUST no longer report that name as queued
- **AND** that cookie MUST NOT be emitted

#### Scenario: Same name on different paths

- **WHEN** two cookies with the same name but different paths are queued
- **THEN** both MUST be retained in the queue
- **AND** both MUST be emitted when the queue is flushed

#### Scenario: Queueing does not emit

- **WHEN** a cookie is queued and the queue is never flushed
- **THEN** no cookie header MUST be written

### Requirement: Queue flushing at response send points

The framework SHALL emit every queued cookie before response output begins, for all response paths the framework supports: site route responses, redirect responses, REST API responses serialized by WordPress, and ordinary page loads.

Flushing MUST clear the queue so that a cookie is never written twice.

#### Scenario: Site route response

- **WHEN** a cookie is queued during a site route and the route returns a response
- **THEN** the cookie MUST be emitted before any response headers or body are sent

#### Scenario: Redirect response

- **WHEN** a cookie is queued and the route returns a redirect
- **THEN** the cookie MUST be emitted before the redirect is issued and the request terminates

#### Scenario: REST API response

- **WHEN** a cookie is queued during a REST route that returns a JSON response
- **THEN** the cookie MUST be emitted even though WordPress serializes the response, not the framework

#### Scenario: Queue is cleared after flushing

- **WHEN** the queue is flushed
- **THEN** the queue MUST be empty afterwards
- **AND** a subsequent flush MUST NOT re-emit the same cookies

### Requirement: Emission after headers are sent is non-fatal

When output has already begun and cookie headers can no longer be written, the framework MUST skip the write and record a warning identifying the cookie. It MUST NOT raise an error or terminate the request.

#### Scenario: Headers already sent

- **WHEN** the queue is flushed after response output has started
- **THEN** no error MUST be raised
- **AND** a warning naming the affected cookie MUST be recorded in the application log

### Requirement: Attaching cookies to responses

Response objects returned from controllers SHALL accept cookies fluently, for a single cookie, several cookies at once, or the removal of a previously attached cookie. Attaching a cookie to a response MUST result in the same emission behavior as queueing it directly.

#### Scenario: Cookie attached to a JSON response

- **WHEN** a controller attaches a cookie to a JSON response and returns it
- **THEN** the cookie MUST be emitted with that response

#### Scenario: Cookie removed from a response before it is returned

- **WHEN** a cookie is attached to a response and then removed by name
- **THEN** that cookie MUST NOT be emitted

### Requirement: Reading request cookies

The request object SHALL expose the cookies sent by the client: a single cookie by name with an optional default, all cookies as an array, and a check for whether a named cookie is present. Cookie values MUST have any transport-level escaping applied by WordPress removed before they are returned.

Request cookies MUST be available for both REST API requests and site requests.

#### Scenario: Reading a cookie by name

- **WHEN** the client sends a cookie and a handler asks the request for it by name
- **THEN** the request MUST return that cookie's value

#### Scenario: Missing cookie returns the default

- **WHEN** a handler asks the request for a cookie the client did not send
- **THEN** the request MUST return the supplied default value

#### Scenario: Cookies on a REST request

- **WHEN** a REST API request carries cookies
- **THEN** the request object MUST expose those cookies, even though the underlying WordPress REST request does not carry them

### Requirement: Request cookies are isolated from request input

Cookies MUST NOT be reachable through the request's input accessors. They MUST NOT appear in the request's combined input, MUST NOT be returned by keyed input reads, MUST NOT be reachable through dynamic property access, and MUST NOT be visible to validation or sanitization of request input.

This prevents an attacker-supplied cookie from satisfying or overriding a validated query or body field.

#### Scenario: Cookie is absent from request input

- **WHEN** the client sends a cookie named the same as an expected body field, and does not send that body field
- **THEN** reading that field from request input MUST NOT return the cookie value
- **AND** the request's combined input MUST NOT contain the cookie

#### Scenario: Cookie is absent from validated data

- **WHEN** a request declares a validation rule for a field name that matches an incoming cookie
- **THEN** the cookie value MUST NOT satisfy that rule

### Requirement: Cookie values are not encrypted

Cookie values written by the framework MUST be stored as supplied, without encryption or signing. Incoming cookie values MUST be treated as untrusted client input and MUST NOT be assumed to be authentic or unmodified.

#### Scenario: Value round-trips unchanged

- **WHEN** a cookie is emitted with a value and the client returns it
- **THEN** the value read from the request MUST equal the value that was written

### Requirement: Sending cookies on outbound requests

The outbound HTTP client SHALL accept cookies to send with a request, either one at a time or as a set, with an optional domain. It MUST accept cookies expressed as name and value pairs, as framework cookie objects, or as WordPress HTTP cookie objects, and MUST carry them through to the transport.

#### Scenario: Outbound request carries a cookie

- **WHEN** a cookie is attached to an outbound HTTP client request
- **THEN** the request sent to the remote host MUST include that cookie

#### Scenario: Mixed cookie input shapes

- **WHEN** cookies are supplied as name and value pairs, as framework cookie objects, and as WordPress HTTP cookie objects
- **THEN** all of them MUST be sent with the outbound request
