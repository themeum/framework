## Why

The validator rebuilds the rule instances for every concrete key a wildcard rule expands to. A product
with 180 variants and 26 `variants.*.<field>` rules expands to 4,681 keys. `RuleParser::process_rules()`
calls `RuleFactory::make()` once per key, so each rule string is parsed 180 times, and each parse builds
about five rule objects. Every object's constructor looks up translated messages. On top of that,
`validate()` runs every rule twice, because `validated()` calls `passes()` again after `fails()` already
did.

Measured on PHP 8.4 with Xdebug off and the 4,681-key payload above:

| Step | Today |
|---|---|
| `Validator::make()` (rule parsing) | 522 ms |
| …of which `RuleFactory::make` per expanded key | 520 ms |
| …the same work done once per raw rule (27 entries) | 2.8 ms |
| `passes()` | 171 ms |
| `validated()` (runs `passes()` again) | 173 ms |

The sanitizer has no parse step: it dispatches on a string per key and takes 2 ms on the same
payload. It is not part of this change.

## What Changes

- `RuleParser` parses each entry of the rules array once, however many concrete keys its wildcard
  expands to. Each expanded key gets its own shallow `clone` of the parsed instances. This includes
  closures and rule objects the caller passes in, which today are shared by every expanded key. The
  cache lasts for one parse only; nothing is cached statically, because a `Rule::when()` outcome
  depends on the data being validated.
- `Validator` remembers whether it has run and what the outcome was:
  - A second call to `passes()` or `fails()` returns the stored outcome instead of validating
    again. This fixes a current bug: calling `fails()` and then `passes()` on invalid data
    reports every error message twice.
  - `validated()` and `validate()` call `passes()` only when validation has not run yet.
  - `sometimes()` (when it adds rules) and `set_value()` throw away the stored outcome and the
    errors, so the next call validates again.
- No public method signatures change, and no **BREAKING** change is intended. Two effects are
  visible to callers:
  - Calling `get_rules()` on a wildcard rule now returns separate cloned instances for each
    key, where a rule object you passed in used to come back as the same object for every key.
  - A repeat `passes()` call no longer appends duplicate errors.

Out of scope, for a separate change: caching the default-message lookups that every rule constructor
and `sync_custom_messages()` performs, and removing the throwaway probe instance that
`RuleFactory::group_under_base_rule()` builds for each piece of a rule string.

## Capabilities

### New Capabilities
- `validation-rule-parsing`: how wildcard rule keys are expanded and turned into rule instances,
  including the guarantee that each source rule is parsed once and that every expanded key gets
  its own instances.
- `validation-run-lifecycle`: when a `Validator` runs its rules, when it reuses a stored outcome,
  and which calls throw that outcome away.

### Modified Capabilities
<!-- None: no validation spec exists yet in openspec/specs/. -->

## Impact

- Code: `src/Validation/RuleParser.php` (wildcard expansion keeps the source key; `process_rules()`
  parses once per source entry and clones), `src/Validation/Validator.php` (run flag, stored
  outcome, invalidation in `sometimes()` / `set_value()`).
- `RuleFactory`, the rule classes, `Sanitizer`: unchanged.
- Tests: new behaviour tests under `tests/Unit/Validation/`.
- Public API: no signature changes; the facade-free `Validator` keeps its methods.
- Docs: covered by the two new capability specs and method docblocks; no `docs/validation.md` in
  this change.
