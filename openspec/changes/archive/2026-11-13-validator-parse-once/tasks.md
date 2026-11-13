## 1. Baseline

- [x] 1.1 Run `composer phpcs && composer test:unit` on a clean tree and record the result
- [x] 1.2 Run `XDEBUG_MODE=off composer test:unit -- openspec/changes/validator-parse-once/bench/ValidatorBenchTest.php` (180 variants × 26 fields) and record the "before" numbers for `Validator::make`, `passes()` and `validated()`

## 2. Rule parsing tests (red)

- [x] 2.1 Add `tests/Unit/Validation/RuleParsingTest.php`: wildcard expands per item, and an empty or missing array produces no keys
- [x] 2.2 Test: string-rule instances for two expanded keys are `assertEquals` but `assertNotSame`
- [x] 2.3 Test: a caller-supplied `Rule::string()` on a wildcard key gives each key its own instance, and changing one leaves the other unchanged
- [x] 2.4 Test: a closure rule on a wildcard key is called once per expanded key with that key's value
- [x] 2.5 Test: `Rule::when()` on a wildcard key selects its branch from each validator's own data (two validators, opposite conditions)
- [x] 2.6 Test: `variants.*.price => 'required|numeric|min:0'` with prices `5`, `-1`, `'abc'` reports errors only for keys 1 and 2
- [x] 2.7 Test: a non-wildcard key given a rule object keeps that same object in `get_rules()`

## 3. Rule parsing implementation (green)

- [x] 3.1 Add `protected array $sources` to `RuleParser` (docblock, `@since 1.0.0`), filled by `explode_wildcards()` with concrete key → source key, and with plain keys mapped to themselves
- [x] 3.2 Change `process_rules()` to parse once per source key: the first concrete key gets the parsed instances and later keys get shallow clones; explain in the docblock why a shallow clone is enough
- [x] 3.3 Run `composer test:unit -- --filter 'RuleParsingTest|ValidatorTest'` and confirm the tests pass

## 4. Run lifecycle tests (red)

- [x] 4.1 Add `tests/Unit/Validation/ValidatorRunLifecycleTest.php`: a counting closure shows `validate()` runs each key once on passing data
- [x] 4.2 Test: `passes()` then `validated()` does not run the rules again
- [x] 4.3 Test: `fails()` then `passes()` on invalid data returns `false`, and each error appears once
- [x] 4.4 Test: after a passing run, `sometimes()` with a true callback adds a failing rule, and `passes()` then returns `false` with the new error
- [x] 4.5 Test: `sometimes()` with a false callback keeps the stored outcome (the counter does not go up)
- [x] 4.6 Test: after a failing run, `set_value()` fills the required field, and `passes()` then returns `true` with no errors

## 5. Run lifecycle implementation (green)

- [x] 5.1 Add `protected ?bool $passed = null` to `Validator` (docblock, `@since 1.0.0`)
- [x] 5.2 `passes()` returns the stored outcome when it is set; otherwise it runs and stores `empty($this->errors)`
- [x] 5.3 `validated()` throws when `!$this->passes()` and no longer runs `passes()` a second time
- [x] 5.4 Add `protected function reset_outcome()` that clears `$passed` and `$errors`, with a docblock noting that subclasses writing `$this->data` must call it
- [x] 5.5 Call `reset_outcome()` from `set_value()`, and from `sometimes()` after its rules are merged
- [x] 5.6 Update the docblocks of `passes()`, `fails()`, `validated()`, `set_value()` and `sometimes()` to describe the caching and invalidation

## 6. Verification

- [x] 6.1 `composer phpcs && composer test:unit` pass
- [x] 6.2 Re-run the benchmark in `bench/` and record the "after" numbers next to the baseline in `design.md`, aiming for `Validator::make` of about 10 ms and `validated()` of about 0 ms after `passes()`
- [x] 6.3 Confirm `RuleFactory`, the classes in `src/Validation/Rules/` and `src/Sanitizer.php` are unchanged in the diff
