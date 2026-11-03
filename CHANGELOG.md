# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
