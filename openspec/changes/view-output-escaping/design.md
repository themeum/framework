## Context

See proposal.md for motivation. Today `TemplateEngine::wrap_layout()` echoes `$content`, and block-theme paths echo `$this->block_header` / `$this->block_footer`. `SiteRouter` echoes `View::render()` and plain string controller returns. Example templates echo `view_data()` without `esc_*`.

`view_data()` stays raw (logic, arrays, context-specific escaping). Escaping is the template author's responsibility at the point of echo.

## Goals / Non-Goals

**Goals:**

- Eliminate `echo $variable` inside `TemplateEngine` layout assembly
- Single documented emit points for assembled HTML
- Docs + examples + tests demonstrate `esc_html` / `esc_attr` / `esc_url` / `wp_kses_post` with `view_data()`

**Non-Goals:**

- New `view_e` / auto-escape helpers
- Auto-`esc_html` on controller string returns
- Adding WordPress PHPCS EscapeOutput rules to this framework's `phpcs.xml.dist`
- Merging into the `view-context-layout-partials` change

## Decisions

### 1. String-return layout assembly

`render_header(): string` and `render_footer(): string` capture classic `get_header()` / `get_footer()` (and block-theme markup) via output buffering and return strings. `wrap_layout(string $content): string` returns `render_header() . $content . render_footer()` with no internal echo.

**Alternative considered:** Keep echoes and add `phpcs:ignore` on every line — rejected; reviewers still see many bare echoes and the API stays hard to compose.

### 2. Output boundaries only

Emit assembled HTML only from:

- `SiteRouter` response dispatch (`template_redirect`)
- `layout-wrapper.php` (`template_include`)

Each boundary gets one targeted `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` noting that dynamic data is escaped in templates.

**Alternative considered:** `wp_kses_post()` at the boundary — rejected; strips legitimate view HTML and does not replace context-aware escaping.

### 3. Inline esc_* in templates (no helpers)

Authors write `echo esc_html((string) view_data('key'));` etc. Document `wp_kses_post` for rich HTML and that plain string controller returns must be pre-safe.

**Alternative considered:** `view_e()` helpers — rejected by product decision; keep API surface small.

### 4. Trusted `do_blocks()` markup

Block header/footer from WordPress APIs are trusted and captured into returned strings without `wp_kses_post` (kses can break block markup).

## Risks / Trade-offs

- [Reviewers still flag SiteRouter / layout-wrapper echoes] → Mitigation: single boundary + clear phpcs:ignore justification; templates show esc_*
- [Classic theme get_header() side effects] → Mitigation: keep current ob_start capture around get_header/get_footer; only change return vs echo of the buffer
- [Merge conflict with layout-partials] → Mitigation: separate change; coordinate if both touch TemplateEngine

## Migration Plan

1. Refactor TemplateEngine string returns (behaviorally equivalent HTML)
2. Update layout-wrapper and SiteRouter boundary comments
3. Fix example views and docs
4. Align unit test fixtures with esc_* convention
5. No consumer API break for `view()` / `view_data()` / `include_view()`
