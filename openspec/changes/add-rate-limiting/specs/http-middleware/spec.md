## Purpose

Describes how a route resolves the middleware it declares — how middleware is named,
how arguments travel from a route declaration to the middleware that runs, and what happens
when a declaration cannot be resolved.

## ADDED Requirements

### Requirement: Middleware may be registered under a short alias

The system SHALL allow a middleware class to be registered under a short name, and SHALL
accept that name anywhere a route declares middleware. Registration SHALL be possible from a
service provider during application boot.

Declaring middleware by its fully qualified class name SHALL continue to work unchanged.

#### Scenario: An alias resolves to its registered class

- **GIVEN** a middleware registered under the alias `throttle`
- **WHEN** a route declares `throttle`
- **THEN** the registered middleware runs for that route

#### Scenario: Class names still work

- **WHEN** a route declares middleware by fully qualified class name
- **THEN** that middleware runs exactly as before, with no alias registration required

#### Scenario: An unknown alias is reported

- **WHEN** a route declares a middleware name that is neither a registered alias nor a resolvable class
- **THEN** an error is raised naming the unresolvable middleware

### Requirement: Middleware declarations may carry arguments

A middleware declaration SHALL accept arguments, written after the middleware name and
separated from it by a colon, with multiple arguments separated by commas. The arguments
SHALL be passed to the middleware when it runs, in the order written.

A declaration without a colon SHALL be treated as carrying no arguments.

#### Scenario: Arguments reach the middleware

- **WHEN** a route declares a middleware with two comma-separated arguments
- **THEN** the middleware receives both values, in the order written

#### Scenario: A single argument is supported

- **WHEN** a route declares a middleware with one argument
- **THEN** the middleware receives that one value

#### Scenario: No arguments is still valid

- **WHEN** a route declares a middleware with no colon
- **THEN** the middleware runs and receives no arguments

#### Scenario: Only the first colon separates name from arguments

- **WHEN** a middleware declaration contains a colon inside its argument list
- **THEN** the name is taken from before the first colon and the remainder is parsed as arguments

### Requirement: Existing middleware is unaffected by argument support

Middleware that accepts no arguments SHALL continue to run without modification. Adding
argument support SHALL NOT change the contract that existing middleware implements, and
SHALL NOT require existing middleware to be rewritten.

#### Scenario: Argument-free middleware keeps working

- **GIVEN** middleware written before argument support existed
- **WHEN** it runs in a route's middleware chain
- **THEN** it behaves exactly as it did previously

### Requirement: Group and route middleware compose with arguments

Middleware declared on a route group SHALL apply to every route within it, including
declarations carrying arguments, and SHALL compose with middleware declared on the
individual route. Group middleware SHALL run before route middleware.

#### Scenario: A group declares a middleware with arguments

- **GIVEN** a group declaring a middleware with arguments
- **WHEN** a route inside that group is requested
- **THEN** the middleware runs for that route with the group's arguments

#### Scenario: Group and route middleware both run

- **GIVEN** a group declaring one middleware and a route inside it declaring another
- **WHEN** the route is requested
- **THEN** the group's middleware runs first, then the route's
