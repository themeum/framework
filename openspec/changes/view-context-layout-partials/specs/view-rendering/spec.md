## Purpose

Defines how site and theme views are rendered: layout wrapping, view-data transport without extract, nested pure-PHP partials, and stack-based view context isolation.

## ADDED Requirements

### Requirement: View data is read only via the view data helper

The framework MUST NOT extract view data into local PHP variables when rendering templates. Templates on both `template_include` and `template_redirect` MUST read controller and shared data exclusively through the view data helper.

#### Scenario: template_redirect template uses view data helper

- **WHEN** a `template_redirect` route controller returns a view with data key `products`
- **THEN** the rendered template MUST obtain that value via the view data helper
- **AND** a bare `$products` variable MUST NOT be injected by the framework

#### Scenario: template_include template uses view data helper

- **WHEN** a `template_include` route controller returns a view with data key `products`
- **THEN** the included template MUST obtain that value via the view data helper

### Requirement: Layout wrapping on template_include

When a `template_include` site route controller returns a view that uses layout wrapping, the framework MUST cause WordPress to load a path that renders the view content wrapped with theme header and footer, equivalent to full view render layout behavior.

#### Scenario: Layout-enabled view returns wrapper path

- **WHEN** a matched `template_include` controller returns `view('shop.products', $data)` with layout enabled
- **THEN** WordPress MUST receive a framework layout wrapper path (not the raw view path alone)
- **AND** the final output MUST include theme header, view content, and theme footer

#### Scenario: Partial view skips layout on template_include

- **WHEN** a matched `template_include` controller returns `view('shop.ajax-list', $data)->partial()`
- **THEN** WordPress MUST receive the resolved raw view filesystem path
- **AND** the framework MUST NOT wrap that response with theme header or footer

### Requirement: Nested include_view partials

The framework SHALL provide an `include_view` helper that renders a nested pure-PHP view by name (dot notation), using only the explicit data array argument plus engine shared data, and echoing the partial output.

#### Scenario: Parent template includes a partial

- **WHEN** a template calls `include_view('shop.filter', ['categories' => $categories])`
- **THEN** the framework MUST resolve and require `shop/filter.php`
- **AND** the partial MUST read `categories` via the view data helper from its own context

#### Scenario: Partial does not inherit parent keys unless passed

- **WHEN** a parent template has view data key `products` and calls `include_view('shop.filter', ['categories' => []])` without passing `products`
- **THEN** the partial MUST NOT receive `products` from the parent context

#### Scenario: Missing partial throws

- **WHEN** `include_view` is called with a view name that cannot be resolved
- **THEN** the framework MUST throw a runtime exception

### Requirement: View context stack for nested partials

The framework SHALL maintain a stack of view contexts. Each nested include pushes a child context and pops it after the partial finishes. The view data helper MUST return data from the innermost stack frame whose resolved path authorizes the caller.

#### Scenario: Nested partial reads its own data

- **WHEN** a partial included via `include_view` calls the view data helper with a key from its explicit data
- **THEN** the helper MUST return that partial's value

#### Scenario: After partial ends parent context is restored

- **WHEN** `include_view` finishes rendering a partial
- **THEN** subsequent view data helper calls from the parent template MUST use the parent context again

### Requirement: Shared data is merged into every context

When preparing a root view context or pushing a partial context, the framework MUST merge TemplateEngine shared data with the explicit view or partial data (explicit keys override shared keys).

#### Scenario: Shared key available in partial

- **WHEN** shared data includes key `site_name` and a partial is included with unrelated data
- **THEN** the partial MUST be able to read `site_name` via the view data helper
