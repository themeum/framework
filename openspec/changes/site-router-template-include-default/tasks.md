## 1. TemplateEngine cleanup

- [x] 1.1 Remove `framework/view/data` from `TemplateEngine::render()`
- [x] 1.2 Remove `framework/view/data` from `TemplateEngine::include()`

## 2. ViewContext and helper

- [x] 2.1 Add `ViewContext` class with filter name generation, register/prepare, active context, caller verification, and shutdown clear
- [x] 2.2 Add `view_data()` helper in `helpers.php`
- [x] 2.3 Skip optional `View::prepare_include_context()` — `SiteRouter` calls `ViewContext::prepare()` directly

## 3. Site router wiring

- [x] 3.1 Change `Route::$default_hook_name` to `TEMPLATE_INCLUDE`
- [x] 3.2 Update `TestCase` default reset for `default_hook_name`
- [x] 3.3 Wire `handle_template_include` to register `ViewContext` when controller returns `View`

## 4. Tests

- [x] 4.1 Add `ViewContextTest` for filter naming, register/get, and unauthorized caller rejection
- [x] 4.2 Extend `SiteRouterTest` for new default hook and include data registration
- [x] 4.3 Ensure `TemplateEngineTest` still passes without `framework/view/data`

## 5. Documentation and examples

- [x] 5.1 Update `docs/routing-examples.md` for new default, `view_data()` usage, layout notes, migration
- [x] 5.2 Verify/update `example/routes/site.php` if needed for the new default
