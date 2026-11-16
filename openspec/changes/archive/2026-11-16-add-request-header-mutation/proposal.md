## Why

`Framework\Http\Request` exposes request headers for reading (`get_header`, `header`, `get_headers`) but offers no way to write them. Middleware is the natural place to enrich a request — resolving a tenant, attaching a trace or correlation id, normalising an auth header — and today it has nowhere to put that information on the header bag the controller reads from. Because the middleware pipeline already passes the same `Request` instance through to the controller, the only missing piece is a mutation API.

## What Changes

- Add `set_header($name, $value)` to `Framework\Http\Request`: replaces the stored values of a header. Scalars are cast to string and stored as a single value; an array of scalars is stored as multiple values; `null` removes the header; any other type throws `InvalidArgumentException`.
- Add `with_headers(array $headers)`: merges many headers at once by applying `set_header` per key, leaving headers not named in the array untouched (a `null` entry removes that header).
- Add `remove_header($name)`: removes a header; a no-op when absent.
- Add `has_header($name)`: reports whether a header is present.
- All four normalise the name exactly as `get_header` does (`X-Tenant` ≡ `x-tenant` ≡ `x_tenant`); the setters return `$this` for chaining.
- Mutations touch the header bag only. `$server`, `ip()` and the originating `WP_REST_Request` are not affected, so middleware cannot alter the client address by setting `X-Forwarded-For`.
- Initialise `Request::$headers` to `[]` so the API works on a freshly constructed request.
- **BREAKING** (for implementers only): the four methods are declared on `Framework\Contracts\Request`. Any downstream class that implements the contract directly must add them. Inside this repository only `Framework\Http\Request` implements it. The change ships as 3.3.4 by decision; callers of the API are unaffected.

## Capabilities

### New Capabilities

- `http-request-headers`: reading and mutating the headers carried by an incoming framework `Request`, including name normalisation, value handling, merge semantics, and the guarantee that header mutation does not affect server parameters or client address resolution.

### Modified Capabilities

<!-- None: no existing spec covers request headers. -->

## Impact

- `src/Http/Request.php` — four new public methods, `$headers` default.
- `src/Contracts/Request.php` — four new method declarations (implementer-facing BC break).
- `tests/Unit/Http/` — new unit tests; `tests/Unit/RouteMiddlewareTest.php` (or a sibling) — a pipeline test proving a middleware-set header reaches the controller.
- `docs/routing-examples.md` §13 — new subsection with a middleware example.
- `CHANGELOG.md` — `[3.3.4]` "Added" entry, noting the contract addition.
