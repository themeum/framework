## Context

See proposal.md — Why. Relevant current state:

- `Request::$headers` holds `normalised_name => [value, ...]`. It is filled from `WP_REST_Request::get_headers()` (REST) or `extract_headers($server)` (raw HTTP). It has no default, so on a fresh `new Request()` it is `null`.
- `prepare_header_name()` lowercases a name and turns `-` into `_`, which matches WordPress's `WP_REST_Request::canonicalize_header_name()`. `get_header()` normalises the name and returns `[0]`.
- `ip()` reads `$this->server` (falling back to `Superglobals::server()`), never `$this->headers`.
- The middleware pipeline (`Route::build_middleware_pipeline`) passes one `Request` instance through, and the REST path keeps it as `resolved_request` for the controller. Instance mutation is therefore enough. `RouteMiddlewareTest::test_middleware_mutations_reach_controller` already proves this for attributes.

## Goals / Non-Goals

**Goals:**
- Give middleware a small, predictable way to write headers, built on the storage shape and normalisation that already exist.

**Non-Goals:**
- Writing headers back to `WP_REST_Request`, `$_SERVER`, or `$this->server`.
- Immutable/`with*`-returns-a-clone semantics (PSR-7 style). The framework mutates `Request` in place everywhere else (`merge`, `set_route_params`, `__set`).
- Changes to `Response` or `Client\Request` header APIs, despite the inconsistent meaning of `with_headers` across them.

## Decisions

**1. `set_header` delegates to a single normaliser.** `set_header` normalises the name via `prepare_header_name()`. It then hands `null` to `remove_header()`. Otherwise it converts the value to a list of strings and assigns `$this->headers[$name] = $values`. `with_headers` is a `foreach` over `set_header`, so per-key behaviour can't drift.
*Alternative:* `array_merge` the normalised map in `with_headers`. Rejected because it would skip casting, null removal and validation.

**2. Validate the whole value before writing.** The value-to-list conversion checks every element (scalar only) before any assignment. A rejected value then leaves the header untouched, as the spec requires. Booleans cast with `(string)` (`true` → `'1'`, `false` → `''`), which is how PHP would serialise them.
*Note:* `with_headers` is not atomic across keys. If a later entry is invalid, earlier entries are already applied. The spec only requires per-header atomicity, and doing more would mean a validate-all pre-pass for no real use case. Document this in the docblock.

**3. Exception type.** `InvalidArgumentException` is already imported in `Request.php` and already used there via `throw_if` in `route()`. Use the same `throw_unless(..., InvalidArgumentException::class)` helper style.

**4. `has_header` uses `array_key_exists` on the normalised name**, not `isset`. A header set to `false` stores `['']`, which is present. `has()` uses `isset` for attributes, but that would be wrong here only for a `null` slot, and `null` is never stored, so either works. `array_key_exists` states the intent.

**5. `$headers` defaults to `[]`.** The property declaration changes to `protected $headers = [];`. The docblock `@var array` already claims this. Both `make_request` and `make_from_http` still overwrite it.

**6. Contract addition.** The four signatures are added to `Framework\Contracts\Request` (user decision). `set_header(string $name, $value)`, `with_headers(array $headers)`, `remove_header(string $name)` and `has_header(string $name)` have no return type declarations, matching the rest of the contract and class.

**7. Docblocks** carry `@since 3.3.4` (user decision), with `@throws InvalidArgumentException` on `set_header` and `with_headers`.

## Risks / Trade-offs

- [Downstream implementers of `Contracts\Request` fatally error on upgrade] → Call it out in the CHANGELOG under 3.3.4. Nothing in this repo or the scoped `example/` implements the contract besides `Http\Request`. Consumers that extend `Http\Request` (the normal pattern) are unaffected.
- [Middleware sets `X-Forwarded-For` / `CF-Connecting-IP` expecting `ip()` to change] → By design it won't (spec: isolation requirement). Say so in the docs subsection.
- [Header names that differ only in `-` vs `_` collide] → This already happens when reading, and WordPress does the same. No change.
- [Partial application on an invalid entry in `with_headers`] → Documented, per decision 2.
