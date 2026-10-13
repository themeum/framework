## Why

WordPress.org plugin reviewers reject bare `echo $variable` of dynamic content. The framework currently echoes layout HTML and view output from `TemplateEngine` and `SiteRouter` without a clear escaping story, and example templates echo `view_data()` without `esc_*`. Reviewers cannot verify escaping when variables are echoed raw.

## What Changes

- Refactor `TemplateEngine::render_header()`, `render_footer()`, and `wrap_layout()` to **return HTML strings** instead of echoing dynamic variables internally
- Keep a single documented output boundary in `SiteRouter` (template_redirect) and `layout-wrapper.php` (template_include), with targeted `phpcs:ignore` where assembled HTML must be emitted
- Document and demonstrate inline escaping in view templates: `esc_html(view_data(...))`, `esc_attr`, `esc_url`, and `wp_kses_post` for trusted rich HTML
- Update example views, routing docs, and tests to match the convention
- Document that plain string controller returns must be pre-escaped safe HTML
- No new `view_e` helpers; `view_data()` remains raw
- No PHPCS ruleset changes in this framework repo

## Capabilities

### New Capabilities

<!-- none -->

### Modified Capabilities

- `view-rendering`: Require string-return layout assembly (no internal variable echoes), template escaping conventions for `view_data()` output, trusted-HTML via `wp_kses_post`, and safe controller string returns

## Impact

- `src/View/TemplateEngine.php` — string-return refactor for header/footer/wrap
- `src/View/layout-wrapper.php` — emit assembled layout once
- `src/Routing/SiteRouter.php` — documented output-boundary phpcs:ignore
- `example/resources/views/*.php` — add `esc_*` around `view_data()` echoes
- `docs/routing-examples.md` — escaping guide
- Unit tests under `tests/Unit/View/` and `tests/Unit/Routing/`
