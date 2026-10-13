## Context

See proposal.md for motivation. Today `handle_template_include` registers `ViewContext` and returns the raw resolved view path — WordPress includes it with no `wrap_layout()`. `TemplateEngine::render_file()` still `extract()`s on `template_redirect`. `TemplateEngine::include()` exists but has no helper and fails silently.

## Goals / Non-Goals

**Goals:**

- Same layout outcome on `template_include` as `View::render()` when layout is enabled
- Strict `view_data()`-only transport on both hooks (no extract)
- Laravel-like `include_view()` for nested pure-PHP partials with isolated stack contexts
- Keep WordPress `template_include` chain for layout routes via a wrapper file path

**Non-Goals:**

- Blade or other templating languages
- Implementing the previously planned scoped `framework/site_view_data/...` filter
- Making `Route::partial()` apply on `template_include`
- Changing REST routing

## Decisions

### 1. Layout wrapper template for template_include

When `View::uses_layout()` is true, `handle_template_include` prepares `ViewContext` with the real view path, then returns a framework stub (`src/View/layout-wrapper.php`). WordPress `load_template` includes the stub; the stub renders the active view content and calls public layout wrap (header + content + footer).

When `View::partial()` was used, return the raw resolved view path as today.

**Alternative considered:** Echo + exit inside `handle_template_include` — rejected; loses template-chain compatibility.

### 2. ViewContext stack

Replace single `$active` with a stack:

- `prepare()` / `push()` add a frame: `template`, `route_name`, `resolved_path`, `data`
- `pop()` removes the top frame
- `get()` walks top → bottom and returns data from the first frame whose `resolved_path` appears in the caller backtrace
- `clear()` empties the stack on shutdown

`include_view` pushes a child with `array_merge(shared, $data)`, requires the file, pops in `finally`.

**Alternative considered:** Flat merge into one context — rejected; key collisions and unclear isolation.

### 3. No extract anywhere

`render_file` requires the template without `extract()`. Data is only in `ViewContext`. Same for partial includes.

**Alternative considered:** Keep extract as undocumented side effect — rejected by product decision for strict consistency.

### 4. include_view helper

Global `Framework\include_view(string $view, array $data = []): void` — resolve, throw if missing, push context, require, pop, echo. Replaces silent `$engine->include()` for app use; engine method can delegate to the same path or be updated to throw.

### 5. Route::partial on template_include

Document and enforce: only `View::partial()` / `View::layout()` control the include-path wrapper. `Route::partial()` continues to affect `template_redirect` dispatch only (existing `with_layout` application when sending response).

### 6. Unify template_redirect render

`View::render()` / `TemplateEngine::render()` push/prepare ViewContext before requiring the file so `view_data()` works the same on both hooks.

## Risks / Trade-offs

- [Breaking extract removal] → Document migration; update examples and tests to `view_data()`
- [Wrapper + WP load_template variables] → Wrapper ignores WP-injected `$posts` etc.; only uses ViewContext
- [Backtrace auth with nested includes] → Each frame stores its own resolved path; stack walk + per-frame auth
- [Double layout if theme also wraps] → Same as today's `template_redirect` wrap_layout behavior; acceptable

## Migration Plan

1. Update templates that used extracted `$vars` on `template_redirect` to `view_data('vars')`
2. Use `->partial()` on the View (not only Route) when skipping header/footer on `template_include`
3. Replace ad-hoc `TemplateEngine::include` with `include_view()`
4. Release notes call out the extract removal as breaking

## Open Questions

None.
