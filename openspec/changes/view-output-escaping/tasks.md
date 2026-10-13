## 1. TemplateEngine string-return refactor

- [x] 1.1 Refactor `TemplateEngine::render_header()` to capture output and return a string (no `echo` of dynamic variables)
- [x] 1.2 Refactor `TemplateEngine::render_footer()` to capture output and return a string (no `echo` of dynamic variables)
- [x] 1.3 Refactor `TemplateEngine::wrap_layout()` to concatenate `render_header() . $content . render_footer()` and return the string with no internal echo

## 2. Output boundaries

- [x] 2.1 Update `layout-wrapper.php` to emit `wrap_layout()` return once with a documented `phpcs:ignore` for assembled HTML
- [x] 2.2 Add documented `phpcs:ignore` at `SiteRouter` view and plain-string emit points for assembled/pre-safe HTML

## 3. Examples and documentation

- [x] 3.1 Update `example/resources/views/info.php` to escape all `view_data()` echoes with `esc_html`
- [x] 3.2 Verify `example/resources/views/sample.php` uses correct escaping; fix any bare dynamic echoes
- [x] 3.3 Expand `docs/routing-examples.md` with escaping patterns (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`) and note that controller string returns must be pre-safe HTML

## 4. Tests and verification

- [x] 4.1 Update `TemplateEngineTest` and related view fixtures to use `esc_html(view_data(...))` where they echo view data
- [x] 4.2 Confirm `SiteRouterTest` still passes for view and string responses after boundary comments
- [x] 4.3 Run PHPUnit and fix any regressions
