## Purpose

Lets consuming plugins send many outbound HTTP requests at the same time through the `Http` facade. Each request keeps the request-building API and the WordPress HTTP API behavior of a single request. Each request gets its own response, in the order the requests were added.

## ADDED Requirements

### Requirement: Pooled requests are built with the full fluent request API

The `Http` facade SHALL provide `pool(callable $callback, array $options = [])`. The callback MUST receive a pool object. Each call on the pool (keyed with `as()`, or a fluent method called on the pool directly) MUST start a new pending request. That request MUST support every fluent method of a single request with the same effect, including headers, timeout, token, user agent, cookies, body, body format, query, options and SSL verification.

A pooled request MUST start with the defaults of a new single request. It MUST NOT inherit settings from the request that `pool()` was called on.

#### Scenario: Fluent options build the same request as a single request
- **WHEN** a pooled request and a single request are built with the same headers, timeout, JSON body and query parameters for the same URL and method
- **THEN** the final URL and the arguments given to the WordPress HTTP layer MUST be identical for both

#### Scenario: Parent request settings are not inherited
- **WHEN** `pool()` is called on a request that already has a base URL and an authorization header
- **AND** the callback adds a pooled request with no base URL or headers of its own
- **THEN** the pooled request MUST be built without the parent's base URL and authorization header

### Requirement: Verb methods record requests instead of sending them

Inside a pool, the verb methods (`get`, `post`, `put`, `patch`, `delete`, `head`, `options`, `send`) MUST NOT send a network request. They MUST record the request and return the pooled request. The pool MUST send the recorded requests only after the callback returns. The callback's return value MUST be ignored.

An invalid HTTP method or an invalid body format MUST throw when the verb is called, as for a single request, and nothing MUST be sent.

#### Scenario: Nothing is sent while the callback runs
- **WHEN** the callback records three requests and then returns
- **THEN** no request MUST be sent before the callback returns
- **AND** all three requests MUST be sent after it returns

#### Scenario: Return value of the callback is ignored
- **WHEN** the callback records two requests and returns nothing
- **THEN** `pool()` MUST still return a response for each of the two requests

### Requirement: Responses are keyed and returned in the order added

`pool()` SHALL return an array with one response for every pooled request, in the order the requests were added. A request keyed with `as($key)` (string or integer) MUST be stored under that key. A request with no key MUST get the next integer index under PHP array-append rules.

Using a key that is already taken by an earlier request in the same pool MUST throw an invalid-argument error when `as()` is called, and nothing MUST be sent.

#### Scenario: Mixed keyed and unkeyed requests
- **WHEN** the callback adds a request keyed `'a'`, then an unkeyed request, then a request keyed `'b'`, then another unkeyed request
- **THEN** the result keys MUST be `'a'`, `0`, `'b'`, `1` in that order
- **AND** each key MUST hold the response of the request that was added at that position

#### Scenario: Duplicate key is rejected
- **WHEN** the callback adds two requests with the key `'a'`
- **THEN** the second `as('a')` MUST throw an invalid-argument error
- **AND** no request MUST be sent

#### Scenario: Empty pool
- **WHEN** the callback adds no requests
- **THEN** `pool()` MUST return an empty array
- **AND** nothing MUST be sent

### Requirement: Failures are isolated per request

A failed pooled request MUST NOT throw, and it MUST NOT change the responses of the other requests. A transport failure, a short-circuit `WP_Error`, a blocked URL or an unsafe URL MUST give that request a response that wraps a `WP_Error`. `successful()` MUST return `false` and `failed()` MUST return `true` for it. This is a fix to the shared response object, so it applies to single requests as well: before this change, `failed()` returned `false` for a `WP_Error`, because the status read as `0`. An HTTP error status (for example 500) MUST give a normal response with that status.

#### Scenario: One request errors and the others succeed
- **WHEN** a pool has three requests and `pre_http_request` returns a `WP_Error` for the second one
- **THEN** the second response MUST wrap that `WP_Error` and report `failed()` as `true`
- **AND** the first and third responses MUST be the same as they would be without the failing request

#### Scenario: A WP_Error response reports failure on a single request too
- **WHEN** a single request (not pooled) gets a `WP_Error` from the WordPress HTTP layer
- **THEN** `failed()` MUST return `true` and `on_error()` MUST run its callback
- **AND** `status()` MUST still return `0`, and `client_error()` and `server_error()` MUST still return `false`

#### Scenario: Server error status
- **WHEN** one pooled request receives a 500 response
- **THEN** its response MUST report status 500 and `successful()` as `false`
- **AND** the other responses MUST be unaffected

### Requirement: Concurrency limits requests in flight

The `concurrency` option SHALL set the maximum number of requests that are sent at the same time. The default MUST be 10. Requests that need the network MUST be sent in consecutive batches of at most `concurrency`, in the order they were added. Requests that are answered before the network (short-circuited, blocked or unsafe) MUST NOT count toward a batch.

A `concurrency` that is not a positive integer MUST throw an invalid-argument error before the callback runs.

#### Scenario: Pool split into batches
- **WHEN** a pool of 25 network requests runs with `concurrency` set to 10
- **THEN** the requests MUST be sent in three batches of 10, 10 and 5, in the order they were added

#### Scenario: Short-circuited requests do not take a batch slot
- **WHEN** a pool of 12 requests runs with `concurrency` set to 10 and `pre_http_request` answers 2 of them
- **THEN** the remaining 10 requests MUST be sent in one batch

#### Scenario: Invalid concurrency
- **WHEN** `pool()` is called with `concurrency` set to `0`, a negative number, or a value that is not a number
- **THEN** it MUST throw an invalid-argument error
- **AND** the callback MUST NOT be called

### Requirement: Pooled requests keep WordPress HTTP API behavior

Each pooled request SHALL go through the same argument handling as `wp_remote_request()` in WordPress 6.8 and later:

- the WordPress default arguments, with the `http_request_timeout`, `http_request_redirection_count`, `http_request_version`, `http_headers_useragent` and `http_request_reject_unsafe_urls` filters, merged under the request's own arguments (HEAD requests default to no redirects);
- then the `http_request_args` filter with the arguments and the URL;
- then the `pre_http_request` filter with `false`, the final arguments and the final URL.

A result from `pre_http_request` that is not `false` MUST be used as that request's response, and that request MUST NOT be sent.

#### Scenario: Short-circuit receives the final URL and arguments
- **WHEN** a pooled GET request with query parameters and a custom header is answered by a `pre_http_request` callback
- **THEN** the callback MUST receive the URL with the query string and arguments that contain the header
- **AND** that request MUST NOT be sent
- **AND** its response MUST wrap the value that the callback returned

#### Scenario: Request arguments filter applies to each pooled request
- **WHEN** an `http_request_args` callback adds a header
- **THEN** every pooled request MUST be sent (or short-circuited) with that header

### Requirement: Pooled requests keep WordPress URL safety checks

A pooled request MUST be rejected with a `WP_Error`, and MUST NOT be sent, if any of these is true:

- `reject_unsafe_urls` is true and the URL fails WordPress URL validation;
- the URL has no valid scheme;
- WordPress blocks external requests to that host (`WP_HTTP_BLOCK_EXTERNAL` without `WP_ACCESSIBLE_HOSTS` allowing it);
- streaming is requested to a directory that is not writable.

Only the plural WordPress key `reject_unsafe_urls` controls URL validation, as on the single-request path. The framework's singular `reject_unsafe_url` default has no effect. A rejection MUST affect only that request.

#### Scenario: Blocked external host
- **WHEN** external requests are blocked, and a pool has one request to an allowed host and one to a blocked host
- **THEN** the blocked request's response MUST wrap a `WP_Error`
- **AND** the allowed request MUST be sent and answered normally

#### Scenario: Unsafe URL rejected
- **WHEN** a pooled request has `reject_unsafe_urls` set to true and its URL fails validation
- **THEN** its response MUST wrap a `WP_Error`
- **AND** the other pooled requests MUST be unaffected

### Requirement: Pooled responses match the single-request response shape

A sent pooled request SHALL produce the same response array that `wp_remote_request()` returns: headers, body, response code and message, cookies, filename, and the `http_response` object. A transport exception MUST become a `WP_Error` with the code `http_request_failed`. A non-blocking request MUST give the same empty response that WordPress returns for one.

After each sent or rejected request, the `http_api_debug` action MUST fire with the response, `'response'`, the Requests class name, the arguments and the URL. A response that is not an error MUST then go through the `http_response` filter with the arguments and the URL. SSL verification MUST use the WordPress CA bundle and the `https_ssl_verify` filter. The proxy settings, redirect handling and `requests-*` hooks of WordPress MUST apply.

#### Scenario: Debug action and response filter run for pooled requests
- **WHEN** a pooled request is sent and gets a 200 response
- **THEN** `http_api_debug` MUST fire once for it with that request's arguments and URL
- **AND** its response MUST be the value returned by the `http_response` filter

#### Scenario: Transport exception becomes a WP_Error
- **WHEN** the transport raises an exception for one pooled request
- **THEN** that request's response MUST wrap a `WP_Error` with the code `http_request_failed` and the exception message
- **AND** the other requests in the same batch MUST be unaffected

### Requirement: Single requests are unchanged

Adding the pool MUST NOT change how a single request is built or sent. A single request MUST still be dispatched through `wp_remote_request()` with the same URL and arguments as before.

#### Scenario: Existing single-request behavior
- **WHEN** a single request is sent with any combination of fluent options
- **THEN** `wp_remote_request()` MUST receive the same URL and arguments as before this change
