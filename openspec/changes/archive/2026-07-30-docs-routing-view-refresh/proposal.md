## Why

Site routing and view management recently changed (`template_include` default, `view_data()`-only transport, `include_view()`, layout wrapper, output escaping), but `docs/routing-examples.md` still mixes outdated wording (e.g. extract/`with()` language) with updated behavior scattered across sections. Plugin authors need one coherent guide that matches the current APIs.

## What Changes

- Refresh [`docs/routing-examples.md`](../../../../docs/routing-examples.md) so it accurately documents the current routing and view system
- Reorganize the table of contents and sections so **View management** is a first-class topic (`view_data`, `include_view`, layout/partial, escaping)
- Remove or rewrite stale references to `extract()` / “extracted with() data”
- Cross-check examples against current helpers and `example/resources/views/`
- No runtime/code behavior changes

## Capabilities

### New Capabilities

<!-- none — docs only; skip_specs: true -->

### Modified Capabilities

<!-- none — no requirement-level behavior changes -->

## Impact

- [`docs/routing-examples.md`](../../../../docs/routing-examples.md) — primary deliverable
- Possibly light alignment notes with example views if docs cite them (no framework API changes)
- Related completed changes for accuracy: `site-router-template-include-default`, `view-context-layout-partials`, `view-output-escaping`
