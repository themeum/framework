## 1. Tests first (red)

- [x] 1.1 Create `tests/Unit/Http/RequestHeaderTest.php` (extends `Framework\Tests\Unit\TestCase`) covering name normalisation: set as `X-Tenant`, read as `x_tenant` / `x-tenant`; `has_header` with alternate spelling
- [x] 1.2 Add tests for `set_header`: new header, replacing an existing one (exactly one value left), int → `'42'`, bool casting, array → multiple ordered values with `header()` returning the first, chaining returns the same instance
- [x] 1.3 Add tests for `null` handling: `set_header(..., null)` removes an existing header; on an absent header it is a no-op without error
- [x] 1.4 Add tests for invalid values: object and nested array throw `InvalidArgumentException` and leave the existing header value unchanged
- [x] 1.5 Add tests for `with_headers`: adds and replaces while keeping unrelated headers; a `null` entry removes; returns the same instance
- [x] 1.6 Add tests for `remove_header` (existing → absent, `header()` returns default; absent → no error) and `has_header` (present / absent)
- [x] 1.7 Add tests for sources: fresh `new Request()` accepts `set_header`; a request from `make_from_http()` with `HTTP_ACCEPT` keeps `Accept` and gains `X-Tenant`; a request from `make_request(WP_REST_Request)` accepts mutation
- [x] 1.8 Add isolation tests: `set_header('X-Forwarded-For', '1.2.3.4')` leaves `ip()` at `REMOTE_ADDR`, both without trusted proxies and with `REMOTE_ADDR` configured in `app.trusted_proxies`
- [x] 1.9 In `tests/Unit/RouteMiddlewareTest.php`, add a header-setting middleware double and tests that a middleware-set `X-Tenant` is visible in a REST controller and in a closure route (mirroring `test_middleware_mutations_reach_controller` / `_closure`)
- [x] 1.10 Run `composer test:unit -- --filter 'RequestHeaderTest|RouteMiddlewareTest'` → verify the new tests fail for the missing methods

## 2. Implementation

- [x] 2.1 `src/Contracts/Request.php`: declare `set_header(string $name, $value)`, `with_headers(array $headers)`, `remove_header(string $name)`, `has_header(string $name)` with full docblocks (`@param`, `@return`, `@throws` where applicable, `@since 3.3.4`)
- [x] 2.2 `src/Http/Request.php`: default `protected $headers = [];`
- [x] 2.3 `src/Http/Request.php`: add `has_header()` and `remove_header()` next to `get_header()`/`header()`, normalising via `prepare_header_name()`
- [x] 2.4 `src/Http/Request.php`: add `set_header()`: normalise the name; on `null` delegate to `remove_header()`; otherwise validate the whole value (scalar or array of scalars) before writing, cast to a list of strings, assign; throw `InvalidArgumentException` via `throw_unless` on invalid values; return `$this`
- [x] 2.5 `src/Http/Request.php`: add `with_headers()` as a loop over `set_header()`; the docblock notes that entries before an invalid one are already applied; return `$this`
- [x] 2.6 Run `composer test:unit -- --filter 'RequestHeaderTest|RouteMiddlewareTest'` → verify all new tests pass

## 3. Docs and changelog

- [x] 3.1 `docs/routing-examples.md` §13: add a "Setting request headers from middleware" subsection with a middleware example using `set_header` / `with_headers` / `remove_header` / `has_header`, stating that `ip()` and server parameters are unaffected; update the table of contents if subsections are listed
- [x] 3.2 `CHANGELOG.md`: add a `## [3.3.4]` section with an "Added" entry for the four methods and a note that `Framework\Contracts\Request` gained them (implementers must add them)

## 4. Verification

- [x] 4.1 Run `composer phpcs && composer test:unit` → verify both pass with no new warnings
- [x] 4.2 Run `openspec validate add-request-header-mutation --strict` → verify the change is valid
