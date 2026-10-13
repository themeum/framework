## 1. Audit and cleanup

- [x] 1.1 Read `docs/routing-examples.md` end-to-end and list stale claims (extract/`with()`, duplicated view guidance, missing TOC entries)
- [x] 1.2 Remove or rewrite “extracted with() data” (and any other extract-era wording) in route-level handler docs

## 2. View management section

- [x] 2.1 Add a dedicated View management section (and TOC entry) consolidating `view_data()`, `include_view()`, layout/partial, and escaping
- [x] 2.2 Trim duplicated view/escaping detail from the Dispatch hooks section; leave a short summary + link to View management
- [x] 2.3 Ensure escaping examples cover `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, and pre-safe controller string returns

## 3. Routing accuracy and examples

- [x] 3.1 Verify setup, site registration, dispatch hooks, controller returns, and layout sections match current `Route` / `SiteRouter` behavior (`template_include` default, layout wrapper, `View::partial()` vs `Route::partial()`)
- [x] 3.2 Cross-check documented view patterns against `example/resources/views/`; fix example views only if they contradict the documented escaping convention
- [x] 3.3 Renumber sections/TOC after any inserts and do a final pass for broken anchors or contradictory “Expected” notes
