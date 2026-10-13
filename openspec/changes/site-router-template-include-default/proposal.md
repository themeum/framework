## Why

The Site router defaults to `template_redirect`, which takes full control of the response and exits early. That conflicts with WordPress core template loading and plugins that expect the normal `template_include` flow. Switching the default to `template_include` fixes compatibility, but WordPress only receives a file path — view data from `view('template', $data)` is lost unless we add a scoped sharing mechanism.

## What Changes

- **BREAKING**: Default site route dispatch hook changes from `template_redirect` to `template_include`
- Controllers can still return `view('template.path', $data)` on `template_include` routes; data is registered in a scoped `ViewContext` and exposed via `view_data()`
- Unique per-route/template WordPress filter for optional data modification; unrelated templates cannot read another route's data
- Explicit `->template_redirect()` (and `Route::set_default_hook(TEMPLATE_REDIRECT)`) preserve today's full-response behavior
- **BREAKING**: Remove unused global `framework/view/data` filter from `TemplateEngine::render()` and `TemplateEngine::include()`

## Capabilities

### New Capabilities

- `site-routing`: Site route dispatch hooks, default hook behavior, and scoped view data sharing for `template_include`

### Modified Capabilities

- (none — no existing specs under `openspec/specs/`)

## Impact

- `Route::$default_hook_name` and test reset defaults
- `SiteRouter::handle_template_include()` registers view context when a `View` is returned
- New `ViewContext` class and `view_data()` helper
- `TemplateEngine` loses `framework/view/data` filter calls
- Docs in `docs/routing-examples.md`
- Consumers relying on the old default must opt into `template_redirect` explicitly
