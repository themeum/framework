## Purpose

Counts attempts against a keyed limit over a decaying time window, so that abusive or
runaway request volume against an endpoint or action can be rejected before it reaches
application code, and so callers learn when they may try again.

## ADDED Requirements

### Requirement: Attempt counting over a decaying window

The limiter SHALL count attempts against an arbitrary caller-supplied key, and SHALL reset
that count once a configured decay period has elapsed since the first counted attempt in
the window. The window SHALL be fixed: it begins at the first attempt and ends a fixed
decay period later, regardless of activity in between.

Counter state SHALL be held in the framework cache, and SHALL expire on its own so that an
abandoned key leaves no residue.

#### Scenario: Attempts accumulate within the window

- **WHEN** three attempts are recorded against the same key inside the decay period
- **THEN** the recorded attempt count for that key is three

#### Scenario: The window resets after decay

- **GIVEN** a key has reached its maximum attempts
- **WHEN** the decay period has fully elapsed
- **THEN** the key is no longer over its limit, and its count has restarted from zero

#### Scenario: Distinct keys do not interfere

- **WHEN** attempts are recorded against two different keys
- **THEN** each key's count reflects only its own attempts

### Requirement: Incrementing reports the resulting count

Incrementing a key's attempt count SHALL return the count **after** the increment, so that a
caller can decide whether a limit is exceeded from a single operation rather than a separate
read followed by a write.

The increment amount SHALL be caller-configurable and default to one.

#### Scenario: Increment returns the new value

- **WHEN** a key with two recorded attempts is incremented by one
- **THEN** the returned value is three

#### Scenario: Incrementing by an explicit amount

- **WHEN** a key is incremented by five
- **THEN** the returned value has advanced by five

### Requirement: Concurrent attempts are counted safely

Attempt counting SHALL be protected against lost updates from concurrent requests. Two
requests arriving simultaneously for the same key SHALL each observe a distinct resulting
count, so that a burst cannot admit more attempts than the limit allows.

Where the underlying storage offers an atomic counter, the limiter SHALL use it. Otherwise
the limiter SHALL serialize the read-and-write using an atomic lock.

#### Scenario: Simultaneous attempts each receive a unique count

- **WHEN** two requests increment the same key at the same moment
- **THEN** one receives a count of one and the other a count of two, and neither result is lost

#### Scenario: A limit is not exceeded under a burst

- **GIVEN** a limit of five attempts per minute
- **WHEN** ten requests for the same key arrive concurrently
- **THEN** exactly five are permitted and five are rejected

### Requirement: Reporting remaining attempts and availability

The limiter SHALL report how many attempts remain for a key against a given maximum, and
SHALL report the number of seconds until attempts become available again once a key is
exhausted.

The limiter SHALL allow a key's recorded attempts to be cleared explicitly, restoring its
full allowance immediately.

#### Scenario: Remaining attempts are reported

- **GIVEN** a maximum of five attempts and two already recorded
- **WHEN** the remaining allowance is queried
- **THEN** three is reported

#### Scenario: Seconds until availability

- **GIVEN** an exhausted key with forty seconds left in its window
- **WHEN** the time until availability is queried
- **THEN** approximately forty seconds is reported

#### Scenario: Clearing restores the allowance

- **GIVEN** an exhausted key
- **WHEN** its attempts are cleared
- **THEN** the key is no longer over its limit

### Requirement: Executing a callback under a limit

The limiter SHALL provide a single operation that accepts a key, a maximum, a callback, and
an optional decay in seconds. It SHALL execute the callback and report success when the key
has attempts remaining, and SHALL decline to execute it and report failure when it does not.

#### Scenario: Callback runs while attempts remain

- **WHEN** the operation is invoked for a key below its maximum
- **THEN** the callback executes and the operation reports success

#### Scenario: Callback is skipped once exhausted

- **GIVEN** a key that has reached its maximum
- **WHEN** the operation is invoked again
- **THEN** the callback does not execute and the operation reports failure

### Requirement: Named limiter definitions

The system SHALL allow limits to be registered under a name, paired with a callback that
receives the incoming request and returns the limit to apply. Registration SHALL be possible
from a service provider during application boot.

A named limiter's callback SHALL be able to vary the limit it returns based on the request,
including the authenticated user, so that different callers can receive different allowances.

#### Scenario: A named limiter is applied by name

- **GIVEN** a limiter registered under the name `uploads`
- **WHEN** a route references `uploads`
- **THEN** the limit returned by that limiter's callback governs the route

#### Scenario: The limit varies by request

- **GIVEN** a limiter whose callback returns a larger limit for authenticated users
- **WHEN** an authenticated request and a guest request arrive
- **THEN** each is governed by the limit its callback returned

#### Scenario: An unknown limiter name is rejected

- **WHEN** a route references a limiter name that was never registered
- **THEN** an error is raised identifying the unknown name

### Requirement: Multiple limits per limiter

A named limiter's callback SHALL be permitted to return several limits instead of one. Every
returned limit SHALL be evaluated in the order given, and the first limit found to be
exceeded SHALL govern the rejection.

#### Scenario: A secondary limit rejects the request

- **GIVEN** a limiter returning ten per minute and one thousand per day
- **WHEN** a caller is within the per-minute limit but has exhausted the daily one
- **THEN** the request is rejected, and the reported retry time reflects the daily window

#### Scenario: Each limit is counted independently

- **WHEN** a request is counted against a limiter returning two limits
- **THEN** both limits' counters advance, and neither is affected by the other's window

### Requirement: Unlimited limits

The system SHALL provide a limit representing no restriction. A route governed by such a
limit SHALL never be rejected and SHALL NOT accumulate counter state.

#### Scenario: An unlimited limit never rejects

- **GIVEN** a limiter returning an unlimited limit
- **WHEN** any number of requests arrive
- **THEN** none are rejected

### Requirement: Segmenting a limit by an arbitrary value

A limit SHALL be able to declare the value that segments it, so that the allowance applies
per user, per address, per submitted field, or per any other caller-chosen value rather than
globally.

A limit that declares no segmenting value SHALL apply to the route as a whole.

#### Scenario: Segmenting by caller

- **GIVEN** a limit segmented by the caller's identity
- **WHEN** two different callers each make requests
- **THEN** each consumes only their own allowance

#### Scenario: An unsegmented limit is global

- **GIVEN** a limit with no segmenting value
- **WHEN** requests arrive from many callers
- **THEN** they all draw down the same shared allowance

### Requirement: Route throttling

Routes SHALL be able to declare a limit, either inline as a maximum and a decay in minutes,
or by naming a registered limiter. Throttling SHALL apply to both REST routes and front-end
site routes, and SHALL be declarable on an individual route or on a group of routes.

A request that exceeds its limit SHALL be rejected with HTTP status 429 and SHALL NOT reach
the route's action.

#### Scenario: An inline limit throttles a route

- **GIVEN** a route declaring three attempts per one minute
- **WHEN** a fourth request arrives within that minute
- **THEN** it is rejected with status 429 and the route's action does not run

#### Scenario: A named limiter throttles a route

- **GIVEN** a route naming a registered limiter
- **WHEN** requests exceed that limiter's limit
- **THEN** they are rejected with status 429

#### Scenario: Site routes are throttled

- **GIVEN** a front-end site route declaring a limit
- **WHEN** requests exceed it
- **THEN** they are rejected with status 429 rather than rendering the route's view

#### Scenario: A group applies a limit to every route within it

- **GIVEN** a route group declaring a limit
- **WHEN** any route inside that group is requested beyond the limit
- **THEN** it is rejected with status 429

#### Scenario: A request within its limit is unaffected

- **WHEN** a request arrives for a throttled route that is within its limit
- **THEN** the route's action runs and its response is returned unchanged

### Requirement: Rate limit response headers

A response from a throttled route SHALL carry the caller's limit and remaining allowance, so
that a well-behaved client can pace itself before being rejected.

A rejected response SHALL additionally carry the number of seconds until the caller may
retry, and the time at which the allowance resets.

#### Scenario: A permitted response advertises the allowance

- **WHEN** a request within its limit is answered
- **THEN** the response reports the applicable limit and how many attempts remain

#### Scenario: A rejected response advertises the retry time

- **WHEN** a request is rejected for exceeding its limit
- **THEN** the response reports status 429, the seconds until retry, and the reset time

#### Scenario: Unthrottled routes are unaffected

- **WHEN** a route that declares no limit is requested
- **THEN** its response carries no rate limit headers

### Requirement: Custom rejection responses

A limit SHALL be able to declare its own response for the rejection case, receiving the
request and the rate limit headers, so that an application can control the body and content
type of a 429 rather than accepting the default.

#### Scenario: A declared response is used

- **GIVEN** a limit declaring a custom rejection response
- **WHEN** a request exceeds that limit
- **THEN** the declared response is returned, still carrying the rate limit headers

### Requirement: Identifying the caller

Throttling SHALL identify a caller by the authenticated user where one exists, and otherwise
by the client's network address. The client address SHALL be resolvable as a general
property of the request, not only within throttling.

#### Scenario: Authenticated callers are identified by user

- **WHEN** two different authenticated users request the same throttled route
- **THEN** each consumes a separate allowance

#### Scenario: Guests are identified by address

- **WHEN** two guests at different addresses request the same throttled route
- **THEN** each consumes a separate allowance

### Requirement: Forwarded addresses are only trusted behind a trusted proxy

The client address SHALL be taken from the connecting address by default. Address headers
supplied by an upstream proxy SHALL be consulted **only** when the connecting address
matches a configured trusted proxy, so that a caller cannot manufacture unlimited distinct
allowances by forging those headers.

The set of trusted proxies SHALL be configurable, SHALL support address ranges, and SHALL
default to trusting none.

#### Scenario: Forged forwarding headers are ignored

- **GIVEN** no trusted proxies are configured
- **WHEN** a request arrives carrying a forwarded-address header
- **THEN** the header is ignored and the connecting address identifies the caller

#### Scenario: A forwarded address is honoured behind a trusted proxy

- **GIVEN** the connecting address is a configured trusted proxy
- **WHEN** a request arrives carrying a forwarded-address header
- **THEN** the forwarded address identifies the caller

#### Scenario: Spoofing cannot multiply allowances

- **GIVEN** no trusted proxies are configured
- **WHEN** one client sends many requests each with a different forged forwarded address
- **THEN** they all draw down a single allowance and the limit still applies

### Requirement: Counter storage is configurable

The cache store used for limiter counters SHALL be configurable independently of the
application's default cache store, and SHALL fall back to the default store when
unconfigured.

#### Scenario: A dedicated store is used when configured

- **GIVEN** a limiter store is configured
- **WHEN** attempts are counted
- **THEN** the counters are held in that store rather than the default one

#### Scenario: The default store is used otherwise

- **WHEN** no limiter store is configured
- **THEN** counters are held in the application's default cache store
