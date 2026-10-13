## ADDED Requirements

### Requirement: template_include honors view layout flag

When a matched `template_include` site route controller returns a view, the framework MUST honor the view's layout flag: layout-enabled views MUST be rendered with theme header and footer wrapping via the framework layout path; partial views MUST return the raw resolved template path without that wrapping.

#### Scenario: Default view gets layout on template_include

- **WHEN** a matched `template_include` controller returns `view('shop.products', $data)` without calling `partial()`
- **THEN** the response path WordPress loads MUST produce output that includes theme header and footer around the view content

#### Scenario: View partial skips layout on template_include

- **WHEN** a matched `template_include` controller returns `view('shop.ajax-list', $data)->partial()`
- **THEN** WordPress MUST receive the resolved filesystem path for that view
- **AND** theme header and footer MUST NOT be applied by the framework

### Requirement: Route partial does not affect template_include layout

On `template_include` dispatch, the framework MUST NOT use `Route::partial()` to control layout wrapping. Only the returned view's layout flag (`View::partial()` / `View::layout()`) controls whether the layout wrapper is used.

#### Scenario: Route marked partial still wraps when view uses layout

- **WHEN** a `template_include` route is registered with `partial()` and the controller returns a layout-enabled view
- **THEN** the framework MUST still apply layout wrapping for that view

## MODIFIED Requirements

### Requirement: Controllers return view with data on template_include

When a site route dispatches on `template_include` and the controller returns a view with a template name and data, the framework MUST register that data in the view context, make it available via the view data helper, and return either a layout wrapper path (layout enabled) or the resolved view filesystem path (partial) for WordPress to include.

#### Scenario: Controller returns view on template_include with layout

- **WHEN** a matched `template_include` site route controller returns `view('shop.product', ['product' => $product])` with layout enabled
- **THEN** WordPress MUST receive a path that renders the view with theme header and footer
- **AND** the template MUST be able to read `product` via the view data helper

#### Scenario: Controller returns partial view on template_include

- **WHEN** a matched `template_include` site route controller returns `view('shop.product', ['product' => $product])->partial()`
- **THEN** WordPress MUST receive the resolved filesystem path for `shop.product`
- **AND** the template MUST be able to read `product` via the view data helper

#### Scenario: Invalid view path on template_include

- **WHEN** a matched `template_include` site route controller returns a view whose template cannot be resolved
- **THEN** the framework MUST treat the response as an error and MUST NOT return an empty or unrelated template path as success

## REMOVED Requirements

### Requirement: Scoped filter for per-route view data

**Reason**: Out of scope for this change; view context stores merged shared + view data directly without a scoped WordPress filter.

**Migration**: Do not rely on `framework/site_view_data/...` filters. Use controller data and TemplateEngine shared data only until a future change reintroduces scoped filters.
