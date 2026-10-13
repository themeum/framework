## Context

See proposal.md for motivation. `docs/routing-examples.md` already covers much of the current system, but view concerns (`view_data`, `include_view`, layout/partial, escaping) are split across §9, §12, and §16, and §10 still mentions “extracted with() data”. Source of truth for accuracy: current `Route` / `SiteRouter` / `TemplateEngine` / helpers, plus completed changes `site-router-template-include-default`, `view-context-layout-partials`, and `view-output-escaping`.

## Goals / Non-Goals

**Goals:**

- One readable guide that matches today’s routing + view APIs
- Clear **View management** section (or contiguous subsection block) covering data transport, nested partials, layout, and escaping
- Remove misleading extract/`with()` wording

**Non-Goals:**

- Changing PHP APIs or runtime behavior
- Writing a separate new docs file (refresh the existing guide)
- Inventing OpenSpec requirement deltas for documentation

## Decisions

### 1. Keep a single guide; restructure TOC

Prefer editing `docs/routing-examples.md` in place over splitting into multiple files. Introduce a dedicated **View management** section that consolidates:

- `view_data()` (dot notation, caller authorization, no extract)
- `include_view()` nested partials and isolation
- Layout vs `View::partial()` / `Route::partial()` hook differences
- Escaping (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`) and safe controller string returns

Dispatch hooks (§9) keep a short pointer to View management instead of duplicating full examples.

**Alternative considered:** New `docs/views.md` — rejected for this change; one entry point is easier for authors discovering site routes.

### 2. Accuracy pass against code and examples

After restructuring, walk the doc and fix any remaining stale claims (especially route-level `template()` + `with()`, and any bare `echo` without escape in examples). Align cited patterns with `example/resources/views/` where useful.

### 3. No code changes unless a doc example is wrong in-repo

If an example view file still contradicts the documented escaping convention, fix that example as part of apply — but do not refactor framework classes.

## Risks / Trade-offs

- [Large doc diff / review noise] → Mitigation: prefer section moves + targeted rewrites over a full rewrite from scratch
- [Duplication between hook section and view section] → Mitigation: hooks section links out; view section owns detail
- [Docs drift again after future API changes] → Mitigation: tasks include a checklist tied to the three completed OpenSpec changes as a verification baseline

## Migration Plan

Docs-only; no consumer migration. Authors reading the updated guide should replace any remaining extract-based mental model with `view_data()` + esc_* at echo sites.
