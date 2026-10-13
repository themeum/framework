## Why

`template_include` routes return a raw view path to WordPress, so theme header/footer wrapping never runs and `View::partial()` is ignored. Nested sub-templates also lack a Laravel-style include helper. View data transport is inconsistent: `template_redirect` uses `extract()` while `template_include` uses `view_data()`.

## What Changes

- **BREAKING**: Remove `extract()` from view rendering; all templates (both dispatch hooks) read data via `view_data()` only
- On `template_include`, layout-enabled views return a framework layout wrapper that renders content and wraps with theme header/footer (same outcome as `View::render()`)
- `View::partial()` on `template_include` returns the raw view path (no header/footer); `Route::partial()` remains `template_redirect`-only
- Add `include_view($view, $data)` helper for nested pure-PHP partials with a push/pop `ViewContext` stack
- Missing partial views throw `RuntimeException` (no silent failure)
- Extend `ViewContext` from a single active context to a stack; `view_data()` reads the innermost authorized frame
- Scoped `framework/site_view_data/...` filter is **not** part of this change

## Capabilities

### New Capabilities

- `view-rendering`: Layout wrapping on `template_include`, `view_data()`-only transport, nested `include_view()` partials, and ViewContext stack semantics

### Modified Capabilities

- `site-routing`: `template_include` View returns must honor layout wrapping vs partial; align with unified `view_data()` transport

## Impact

- `ViewContext`, `TemplateEngine`, `SiteRouter::handle_template_include()`, `View::render()` / `send_response()`
- New `include_view()` helper and layout wrapper template
- Tests under `tests/Unit/View/` and `tests/Unit/Routing/`
- Docs in `docs/routing-examples.md`
- Consumers of `template_redirect` templates that rely on extracted `$variables` must migrate to `view_data()`
