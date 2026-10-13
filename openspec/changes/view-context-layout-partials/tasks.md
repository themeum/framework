## 1. ViewContext stack

- [x] 1.1 Refactor `ViewContext` from single `$active` to a stack with `push`, `pop`, and `clear` (clears entire stack)
- [x] 1.2 Update `prepare()` to push a root frame (shared + view data, resolved path, route name)
- [x] 1.3 Update `get()` to walk stack top→bottom and return data from the first frame authorizing the caller via backtrace
- [x] 1.4 Update existing `ViewContextTest` for stack semantics; add nested push/pop and isolation tests

## 2. TemplateEngine no-extract rendering

- [x] 2.1 Remove `extract()` from `render_file`; require templates with ViewContext as sole data source
- [x] 2.2 Update `render()` to push/prepare ViewContext before requiring the view, then optional `wrap_layout`
- [x] 2.3 Make layout wrapping publicly callable for the layout wrapper stub
- [x] 2.4 Update `TemplateEngineTest` / related tests to use `view_data()` instead of extracted variables

## 3. Layout wrapper on template_include

- [x] 3.1 Add `src/View/layout-wrapper.php` that renders active view content and wraps with header/footer
- [x] 3.2 Update `SiteRouter::handle_template_include` to return wrapper path when `View::uses_layout()` is true, raw path when `partial()`
- [x] 3.3 Document that `Route::partial()` does not control `template_include` layout
- [x] 3.4 Add/update `SiteRouterTest` for layout wrapper vs partial path returns

## 4. include_view helper

- [x] 4.1 Add `Framework\include_view($view, $data = [])` that resolves path, throws if missing, pushes context, requires file, pops, echoes
- [x] 4.2 Align `TemplateEngine::include` with throw-on-missing behavior (or delegate to include_view path)
- [x] 4.3 Add `IncludeViewTest` covering render, data isolation, and missing-view exception

## 5. Unify template_redirect path

- [x] 5.1 Ensure `View::render()` / `send_response` path uses the same ViewContext + no-extract pipeline
- [x] 5.2 Fix any tests or example templates that relied on extracted variables

## 6. Documentation

- [x] 6.1 Update `docs/routing-examples.md` for layout on `template_include`, `View::partial()`, `include_view()`, and extract→`view_data()` migration
