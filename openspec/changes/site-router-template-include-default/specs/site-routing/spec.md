## Purpose

Defines how site routes dispatch through WordPress template hooks and how controller view data is shared safely into templates loaded via `template_include`.

## ADDED Requirements

### Requirement: Default site route dispatch uses template_include

The framework SHALL dispatch site routes on the WordPress `template_include` filter by default unless a route or global setting overrides the hook.

#### Scenario: New site route uses template_include by default

- **WHEN** a site route is registered without an explicit dispatch hook
- **THEN** the route's dispatch hook MUST be `template_include`

#### Scenario: Global default can be restored to template_redirect

- **WHEN** the application sets the default site route hook to `template_redirect`
- **THEN** newly registered site routes without an explicit hook MUST use `template_redirect`

### Requirement: Explicit template_redirect preserves full-response behavior

The framework SHALL allow a site route to opt into `template_redirect` dispatch, and on that hook MUST send the controller response (view HTML, redirect, JSON, or string) and exit as it does today.

#### Scenario: Route opts into template_redirect

- **WHEN** a site route is registered with `template_redirect`
- **THEN** a matched request MUST be handled by rendering or sending the controller return value and exiting, without returning a template path to WordPress

### Requirement: Controllers return view with data on template_include

When a site route dispatches on `template_include` and the controller returns a view with a template name and data, the framework MUST resolve that view to a filesystem template path for WordPress to include and MUST make the view data available to that template.

#### Scenario: Controller returns view on template_include

- **WHEN** a matched `template_include` site route controller returns `view('shop.product', ['product' => $product])`
- **THEN** WordPress MUST receive the resolved filesystem path for `shop.product`
- **AND** the template MUST be able to read `product` via the view data helper

#### Scenario: Invalid view path on template_include

- **WHEN** a matched `template_include` site route controller returns a view whose template cannot be resolved
- **THEN** the framework MUST treat the response as an error and MUST NOT return an empty or unrelated template path as success

### Requirement: View data is accessible only from the matched template

The framework SHALL expose view data through a helper that returns values only when called from the active matched template (or a file included from it). Calls from unrelated templates or code MUST NOT receive another route's data.

#### Scenario: Matched template reads its data

- **WHEN** the active `template_include` template calls the view data helper with a key that was passed to `view()`
- **THEN** the helper MUST return that value

#### Scenario: Unrelated caller cannot read active view data

- **WHEN** code that is not the active matched template (and not included from it) calls the view data helper
- **THEN** the helper MUST return the provided default (or an empty array when requesting all data)

#### Scenario: Helper returns full data bag

- **WHEN** the matched template calls the view data helper without a key
- **THEN** the helper MUST return the full data array for the active context

### Requirement: Scoped filter for per-route view data

The framework SHALL apply a unique WordPress filter named from the site namespace, route identity, and normalized template path so plugins can modify only that route's view data before it is stored for the template.

#### Scenario: Scoped filter runs before template access

- **WHEN** view data is registered for a `template_include` route
- **THEN** the framework MUST apply the scoped filter for that namespace, route, and template
- **AND** the filtered data MUST be what the view data helper returns

### Requirement: No global framework/view/data filter

The framework MUST NOT apply a global `framework/view/data` filter when rendering views on `template_redirect` or when preparing view data for `template_include`.

#### Scenario: template_redirect render without global data filter

- **WHEN** a view is rendered on the `template_redirect` path
- **THEN** template data MUST be the merge of shared engine data and the view's data only, without applying `framework/view/data`

#### Scenario: template_include prepare without global data filter

- **WHEN** view data is prepared for a `template_include` route
- **THEN** the framework MUST NOT apply `framework/view/data` before or instead of the scoped filter
