## Context

See proposal.md for motivation. Today `SiteRouter` supports both `template_redirect` (default) and `template_include`. On `template_include`, a returned `View` is resolved to a path only — `$data` is dropped. `TemplateEngine::render()` currently applies an unused global `framework/view/data` filter.

## Goals / Non-Goals

**Goals:**

- Make `template_include` the default dispatch hook
- Keep the same controller API: `return view('path', $data)`
- Share data into WordPress-included templates via a scoped registry + helper
- Prevent hijacking of one route's data from another template
- Remove the unused global `framework/view/data` filter

**Non-Goals:**

- Changing REST routing
- Auto-`extract()` of variables into `template_include` template scope
- Applying `View::layout()` / `partial()` on the `template_include` path (theme handles layout)
- Rewriting how `template_redirect` sends redirects/JSON/strings

## Decisions

### 1. Default hook = `template_include`

Change `Route::$default_hook_name` to `HookNames::TEMPLATE_INCLUDE`. Per-route `->template_redirect()` and `Route::set_default_hook()` remain for opt-out.

**Alternative considered:** Keep `template_redirect` as default and document `template_include` as opt-in — rejected because core compatibility is the primary driver.

### 2. `ViewContext` registry + scoped filter

New `Framework\View\ViewContext` holds the active request's view data.

Filter name:

```
framework/site_view_data/{site_namespace}/{route_id}/{normalized_template}
```

- `site_namespace` — site router namespace (e.g. `kirki`)
- `route_id` — existing `SiteRouter::route_id()` hash
- `normalized_template` — `shop.product` → `shop/product` (sanitized)

Registration in `handle_template_include` when controller returns `View`:

1. Merge `TemplateEngine` shared data with view data
2. Apply scoped filter
3. Store result as active context (route name, template, resolved path, data)
4. Return resolved path to WordPress

**Alternative considered:** Reuse global `framework/view/data` — rejected; isolation requires a per-route filter and the global filter is unused.

### 3. Caller verification via backtrace

`view_data()` reads only the active context and verifies the caller file is the registered resolved path (or a path included under that include chain via backtrace file entries matching the resolved path). Unauthorized callers get `$default` / `[]`.

**Alternative considered:** Trust any caller during the request — rejected; user asked to prevent hijacking.

### 4. Active context lifecycle

One active context per request. Cleared on `shutdown`. Registering again replaces the previous context.

### 5. Remove `framework/view/data`

Delete both `apply_filters('framework/view/data', ...)` calls from `TemplateEngine`. `template_redirect` continues to `extract()` after merging shared + view data only.

### 6. Layout behavior

Document that `partial()` / `layout()` apply only on `template_redirect` via `TemplateEngine::render()`. On `template_include`, WordPress/theme provide the layout.

## Risks / Trade-offs

- [Breaking default hook] → Document migration; keep `set_default_hook` and per-route opt-in
- [Breaking filter removal] → Nothing in-repo hooks it; document scoped filter for external plugins
- [Backtrace verification cost/fragility] → Only runs when `view_data()` is called; acceptable for template rendering
- [No extract() on include path] → Templates must use `view_data()` — intentional and documented

## Migration Plan

1. Release notes: default hook is now `template_include`
2. Routes needing early exit / JSON / full `View::render()` layout: add `->template_redirect()` or set global default back
3. Templates on include path: read data with `view_data('key')` instead of expecting extracted variables
4. Plugins using `framework/view/data`: migrate to scoped `framework/site_view_data/...` filters

## Open Questions

None.
