# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.3.2]

### Fixed

- Route controllers are now built by the application container. Constructor
  dependencies follow the same rules as action-method parameters: bindings,
  singletons, interfaces bound in the container, and default values for scalar
  parameters. Previously `Route` built controllers with its own injector, which
  ignored the container. A singleton injected into the constructor was then a
  different object from the same singleton injected into the action, a bound
  interface failed with `Class "..." does not exist`, a scalar parameter failed
  even when it had a default, and unbound services were shared between unrelated
  controllers. A controller is now built fresh on each dispatch; bind it with
  `app()->singleton()` to share one instance. `Route::make()` keeps its signature
  and delegates to the container. The protected static cache it used is removed:
  `Route::$instances`, `cache()`, `is_cached()` and `get_cached()`. See
  `docs/routing-examples.md`.

## [3.3.1]

### Added

- A per-store `version_scoped` cache setting (default `false`). A store with
  `'version_scoped' => true` starts cold when the application version changes:
  the first request on the new version that uses the store flushes it once,
  through the ordinary `flush()`, so superseded entries are still queued for the
  scheduled sweep. A store with no recorded version counts as changed. Turn it on
  for stores that hold values whose shape your own code produces, so that an
  update never reads an entry written in the previous release's shape. Every
  `database` store, and every `file` store on the default path, shares one
  namespace, so scoping one flushes all of them. See `docs/cache.md`.

## [3.3.0]

### Added

- `Http::pool()` sends many HTTP requests concurrently through WordPress's bundled
  `WpOrg\Requests` transport, in batches of a configurable `concurrency` (default 10).
  Pooled requests keep the full fluent request API and the WordPress HTTP API
  behaviour: default-argument filters, `http_request_args`, `pre_http_request`,
  URL safety checks, proxy, `http_api_debug` and `http_response`. A failed request
  returns a `Response` that wraps a `WP_Error` and does not affect the others.
  See `docs/http-client.md`.

### Fixed

- `Response::failed()` now returns `true` when the request failed with a `WP_Error`.
  It previously returned `false`, because the status read as `0`.
