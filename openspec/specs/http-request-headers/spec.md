## Purpose

Defines how code handling an incoming framework request — middleware in particular — reads, sets, merges, and removes the headers the request carries, and guarantees that doing so never changes server parameters or how the client address is resolved.

## Requirements

### Requirement: Header names are matched case- and separator-insensitively

Every header operation on the request (read, set, merge, remove, presence check) SHALL treat a header name case-insensitively and SHALL treat `-` and `_` as equivalent, so that `X-Tenant`, `x-tenant`, and `x_tenant` all refer to the same header.

#### Scenario: Set with one spelling, read with another

- **WHEN** a header is set as `X-Tenant` with value `acme`
- **THEN** reading `x_tenant` MUST return `acme`
- **AND** reading `x-tenant` MUST return `acme`

#### Scenario: Presence check uses the same matching

- **WHEN** a header is set as `X-Tenant`
- **THEN** checking for `x_tenant` MUST report it present

### Requirement: Setting a single header replaces its values

The request SHALL allow a single header to be set. Setting a header MUST replace any values previously held for it. A scalar value (string, integer, float, or boolean) MUST be stored as one string value. An array of scalars MUST be stored as that many string values, in order. The operation MUST return the request itself so calls can be chained.

#### Scenario: Setting a new header

- **WHEN** a header `X-Trace` that is not present is set to `abc`
- **THEN** reading `X-Trace` MUST return `abc`

#### Scenario: Setting an existing header replaces it

- **WHEN** the request carries `X-Trace` with value `a`
- **AND** `X-Trace` is set to `b`
- **THEN** reading `X-Trace` MUST return `b`
- **AND** the full header list MUST hold exactly one value, `b`, for `X-Trace`

#### Scenario: Scalar values are stored as strings

- **WHEN** `X-User-Id` is set to the integer `42`
- **THEN** reading `X-User-Id` MUST return the string `42`

#### Scenario: Array values are stored as multiple values

- **WHEN** `Accept` is set to the array `['text/html', 'application/json']`
- **THEN** the full header list MUST hold the values `text/html` and `application/json` for `Accept`, in that order
- **AND** reading `Accept` MUST return `text/html`

#### Scenario: Setting returns the request for chaining

- **WHEN** two headers are set in one chained expression
- **THEN** both headers MUST be present on the request

### Requirement: Setting a header to null removes it

Setting a header to `null` SHALL remove that header from the request, with the same effect as removing it explicitly.

#### Scenario: Null removes an existing header

- **WHEN** the request carries `X-Debug`
- **AND** `X-Debug` is set to `null`
- **THEN** the request MUST report `X-Debug` as not present

#### Scenario: Null on an absent header is harmless

- **WHEN** `X-Debug` is not present
- **AND** `X-Debug` is set to `null`
- **THEN** no error MUST be raised
- **AND** `X-Debug` MUST remain not present

### Requirement: Invalid header values are rejected

Setting a header to a value that is not `null`, a scalar, or an array whose every element is a scalar SHALL fail with an invalid-argument error, and MUST leave the request's headers unchanged.

#### Scenario: Object value is rejected

- **WHEN** a header is set to an object
- **THEN** an invalid-argument error MUST be raised
- **AND** the request's headers MUST be unchanged

#### Scenario: Nested array value is rejected

- **WHEN** a header is set to an array containing an array
- **THEN** an invalid-argument error MUST be raised
- **AND** the request's headers MUST be unchanged

### Requirement: Merging many headers keeps unrelated headers

The request SHALL allow several headers to be set in one call from a name-to-value map. Each entry MUST behave exactly as setting that header individually (replace, cast, `null` removes, invalid values rejected). Headers already on the request that are not named in the map MUST be left unchanged. The operation MUST return the request itself.

#### Scenario: Merge adds and replaces while keeping others

- **WHEN** the request carries `Accept` and `X-Trace: a`
- **AND** the map `X-Trace => b, X-Tenant => acme` is merged
- **THEN** `Accept` MUST be unchanged
- **AND** reading `X-Trace` MUST return `b`
- **AND** reading `X-Tenant` MUST return `acme`

#### Scenario: Merge with a null entry removes that header

- **WHEN** the request carries `X-Debug`
- **AND** the map `X-Debug => null` is merged
- **THEN** the request MUST report `X-Debug` as not present

### Requirement: Removing a header

The request SHALL allow a header to be removed. Removing a header that is not present MUST be a no-op. The operation MUST return the request itself.

#### Scenario: Remove an existing header

- **WHEN** the request carries `Authorization`
- **AND** `Authorization` is removed
- **THEN** the request MUST report `Authorization` as not present
- **AND** reading `Authorization` with a default MUST return the default

#### Scenario: Remove an absent header

- **WHEN** `X-Missing` is not present
- **AND** `X-Missing` is removed
- **THEN** no error MUST be raised

### Requirement: Checking header presence

The request SHALL report whether a header is present, using the name matching defined above.

#### Scenario: Present and absent headers

- **WHEN** the request carries `Content-Type` and not `X-Missing`
- **THEN** checking `content-type` MUST report present
- **AND** checking `X-Missing` MUST report not present

### Requirement: Header mutation works on a request built from any source

Setting, merging, removing, and checking headers SHALL work identically on a request built from a WordPress REST request, a request captured from raw HTTP input, and a freshly constructed request with no headers.

#### Scenario: Fresh request accepts headers

- **WHEN** a request is constructed with no input
- **AND** `X-Tenant` is set to `acme`
- **THEN** reading `X-Tenant` MUST return `acme`

#### Scenario: Request from raw HTTP input

- **WHEN** a request is built from server parameters carrying `HTTP_ACCEPT`
- **AND** `X-Tenant` is set to `acme`
- **THEN** both `Accept` and `X-Tenant` MUST be present

### Requirement: Header mutation is isolated from server parameters and client address

Changing headers on the request SHALL NOT change the request's server parameters, and SHALL NOT change the client address the request resolves. In particular, setting a forwarded-address header MUST NOT affect client address resolution, whether or not trusted proxies are configured.

#### Scenario: Setting X-Forwarded-For does not change the client address

- **WHEN** a request has a connecting address of `10.0.0.5`
- **AND** `X-Forwarded-For` is set to `1.2.3.4` on the request
- **THEN** the resolved client address MUST still be `10.0.0.5`

#### Scenario: Setting X-Forwarded-For with a trusted proxy still has no effect

- **WHEN** the connecting address `10.0.0.5` is a configured trusted proxy
- **AND** the original server parameters carry no forwarded-address header
- **AND** `X-Forwarded-For` is set to `1.2.3.4` on the request
- **THEN** the resolved client address MUST still be `10.0.0.5`

### Requirement: Headers set by middleware reach the route handler

A header set on the request by route middleware SHALL be visible to the controller or closure that handles that route, on both REST and site routes.

#### Scenario: Middleware-set header visible in a REST controller

- **WHEN** a REST route's middleware sets `X-Tenant` to `acme` on the request
- **AND** the route's controller reads `X-Tenant` from the request it receives
- **THEN** the controller MUST see `acme`

#### Scenario: Middleware-set header visible in a closure route

- **WHEN** a route's middleware sets `X-Tenant` to `acme`
- **AND** the route is handled by a closure that reads `X-Tenant`
- **THEN** the closure MUST see `acme`
