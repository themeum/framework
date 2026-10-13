## Purpose

Defines how an exception raised while handling a request becomes an HTTP response — the
status code, message, and headers a caller receives — consistently across REST routes and
front-end site routes.

## ADDED Requirements

### Requirement: Exceptions may declare an HTTP status and headers

The system SHALL provide an exception type that carries an HTTP status code, a message, and
an optional set of response headers. Raising it from anywhere in request handling SHALL
produce a response with that status, message, and headers.

#### Scenario: The declared status is used

- **WHEN** an exception declaring status 429 is raised while handling a request
- **THEN** the response carries status 429

#### Scenario: Declared headers reach the response

- **WHEN** an exception declaring response headers is raised
- **THEN** those headers appear on the response

#### Scenario: A machine-readable error code is exposed

- **WHEN** such an exception is raised on a REST route
- **THEN** the error payload identifies the error by a code specific to the failure, not a generic one

### Requirement: Middleware may reject a request with a status

Middleware SHALL be able to reject a request before it reaches the route's action, producing
a response with a chosen status code and headers. This SHALL work identically whether the
route is a REST route or a front-end site route.

#### Scenario: Middleware rejects a REST request

- **WHEN** middleware on a REST route raises an exception declaring a status and headers
- **THEN** the caller receives that status, and the route's action does not run

#### Scenario: Middleware rejects a site request

- **WHEN** middleware on a site route raises an exception declaring a status
- **THEN** the caller receives that status, and the route's view is not rendered

### Requirement: Existing error mappings are preserved

Adding status-carrying exceptions SHALL NOT change how the system already maps validation
failures, authorization failures, and missing records to responses.

#### Scenario: Authorization failures still map to their existing status

- **WHEN** an authorization failure is raised
- **THEN** the caller receives the same status they received before this change

#### Scenario: Validation failures still map to their existing status and payload

- **WHEN** a validation failure is raised
- **THEN** the caller receives the same status and error payload they received before this change

### Requirement: Unrecognized statuses fall back safely

An exception declaring a status outside the valid HTTP range SHALL be answered with a
server error rather than an invalid status being emitted.

#### Scenario: An out-of-range status is replaced

- **WHEN** an exception declares a status outside the valid HTTP range
- **THEN** the response carries a 500 status
