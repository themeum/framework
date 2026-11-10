# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
