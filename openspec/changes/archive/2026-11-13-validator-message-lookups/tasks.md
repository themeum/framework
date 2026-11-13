## 1. Baseline

- [x] 1.1 Run `composer phpcs && composer test:unit` and record the result (expect the three existing line-length warnings only)
- [x] 1.2 Record the "before" `passes()` time for the stress payload (180 variants) with `XDEBUG_MODE=off` and `XDEBUG_MODE=debug` in `design.md`

## 2. Custom message tests (lock in current behaviour; must pass before any src change)

- [x] 2.1 Add `tests/Unit/Validation/CustomMessagesTest.php`: flat `name.required` and nested `['name' => ['required' => ...]]` custom messages
- [x] 2.2 Test: `name.max` custom message with `{max}` placeholder is reported as `At most 3`
- [x] 2.3 Test: a closure custom message receives the name and value (`Closure name=5`)
- [x] 2.4 Test: a custom message for `v.1.s.max` applies to that key only; `v.0.s` reports the default
- [x] 2.5 Test: custom messages for another field leave `name` on the default message
- [x] 2.6 Test: one rule object used in validator A (custom `name.max`) and then validator B (no custom messages) reports `Custom A` and then the default
- [x] 2.7 Test: a required field with no custom messages reports the default required message
- [x] 2.8 Run the new tests on the unchanged code and confirm all pass

## 3. No-lookup test (red)

- [x] 3.1 Add `tests/Support/Validation/CountingMessagesBag.php`, a `MessagesBag` subclass that counts `get()` calls
- [x] 3.2 Test: with the counting bag bound as the singleton, `passes()` on a passing wildcard payload without custom messages makes 0 lookups; confirm it fails today

## 4. Implementation (green)

- [x] 4.1 `MessagesBag::get()` reads `$this->messages` instead of calling `messages()`
- [x] 4.2 `ValidationRule::sync_custom_messages()` returns early when both the incoming and current custom messages are empty; the docblock explains why this is exact and the subclass caveat
- [x] 4.3 Run `composer test:unit -- --filter 'CustomMessagesTest|ValidatorTest|RuleParsingTest|ValidatorRunLifecycleTest'` and confirm all pass

## 5. Verification

- [x] 5.1 `composer phpcs && composer test:unit` pass (no new warnings)
- [x] 5.2 Record the "after" `passes()` times (Xdebug off and on) in `design.md`
- [ ] 5.3 Ask the user to re-run `GET /validation-stress?variants=180` in the playground and add the web number
- [x] 5.4 Confirm the diff under `src/` touches only `MessagesBag.php` and `ValidationRule.php`
