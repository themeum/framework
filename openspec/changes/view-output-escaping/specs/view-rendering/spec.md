## ADDED Requirements

### Requirement: Layout assembly returns HTML strings

The view rendering engine MUST assemble theme header, view content, and theme footer as HTML strings. Layout assembly methods MUST NOT echo dynamic variables internally. Assembled page HTML MAY be emitted only at documented output boundaries (site route response dispatch and the template_include layout wrapper).

#### Scenario: Layout wrap returns a string

- **WHEN** a layout-enabled view is rendered
- **THEN** the layout assembly step MUST return a single HTML string containing header, content, and footer
- **AND** the layout assembly step MUST NOT echo dynamic variables during assembly

#### Scenario: Block theme header and footer are captured as strings

- **WHEN** layout wrapping runs on a block theme
- **THEN** block template-part markup MUST be captured into returned strings
- **AND** the engine MUST NOT echo those dynamic markup variables during header or footer assembly

#### Scenario: Classic theme header and footer are captured as strings

- **WHEN** layout wrapping runs on a classic theme
- **THEN** theme header and footer side effects MUST be captured into returned strings via output buffering
- **AND** the engine MUST NOT leave unrebuffered echo of dynamic layout variables in those assembly methods

### Requirement: Template authors escape view data at output

View templates that output values from the view data helper MUST escape those values with the WordPress escaping function appropriate to the HTML context before echoing. The view data helper itself MUST continue to return raw values for use in logic and non-HTML contexts.

#### Scenario: Text content is escaped for HTML body

- **WHEN** a template outputs a view data string into HTML body text
- **THEN** the template MUST wrap that value with `esc_html` (or an equivalent WordPress HTML-text escape) before echoing

#### Scenario: Attribute and URL contexts use matching escapes

- **WHEN** a template outputs a view data value into an HTML attribute or URL context
- **THEN** the template MUST use `esc_attr` or `esc_url` respectively (or equivalent WordPress functions) before echoing

#### Scenario: Trusted rich HTML uses allowlist sanitization

- **WHEN** a template intentionally outputs HTML from a view data field (for example WYSIWYG content)
- **THEN** the template MUST sanitize with `wp_kses_post` (or a documented allowlist equivalent) before echoing
- **AND** the template MUST NOT use `esc_html` for that field if markup is required

### Requirement: Controller string returns are safe HTML

When a site route controller returns a plain string (not a view object or response object), that string MUST be treated as ready-to-emit HTML that the controller has already escaped or otherwise made safe. The framework MUST NOT automatically run `esc_html` on the entire string return.

#### Scenario: Plain string response is emitted as provided

- **WHEN** a matched site route controller returns a plain string on the template_redirect path
- **THEN** the framework MUST emit that string at the response boundary without wrapping it in `esc_html`
- **AND** documentation MUST state that authors are responsible for pre-escaping that string
