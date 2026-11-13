## Context

See proposal.md (Why) for the measurements.

`Validator::__construct()` → `parse_rules()` → `RuleParser::parse()` runs two passes:

1. `explode_wildcards()` replaces each `*` key with its concrete keys. Every concrete key is given
   the same raw rule value (string, array, object or closure). The source key is not kept.
2. `process_rules()` loops over the concrete keys and, for each one, calls
   `RuleFactory::make(spread(make_array($rules)))`.

The output of step 2 depends only on the raw value and `$this->data`. `spread()` calls
`ConditionalRule::passes($this->data)` with the whole dataset, not with the key. So for a
given parse, every concrete key that comes from one source entry produces the same output.

Rule instances are stateful but only in a resettable way. `Validator::apply()` calls `with_data`,
`with_name`, `with_value`, `clear_error_messages` and `sync_custom_messages` before every
`passed()`. Every property on `ValidationRule`, `Fluent` and the classes in `Rules/` is a scalar
or an array, so a shallow `clone` produces a fully independent copy.

`Validator::passes()` never resets `$errors`. `validated()` calls `passes()` again whenever
`$errors` is empty, which is always the case after a successful `fails()`.

## Goals / Non-Goals

**Goals:**
- One `RuleFactory::make()` call per source entry, scoped to a single `RuleParser`.
- One run of the rules per `Validator` state.
- Same pass/fail results, messages and `validated()` output as today.

**Non-Goals:**
- No change to `RuleFactory`, the rule classes, message resolution, or `Sanitizer`.
- No static or cross-validator cache.
- No change to the quadratic `preg_grep` in `validated()` (noted, separate).
- No change to wildcard-expansion semantics (`array_merge` re-indexing, empty-array handling).

## Decisions

### D1. RuleParser records the source key for each concrete key

`explode_wildcards()` fills a new `protected array $sources` (concrete key → source key). It
records each key from `explode_array_rule()` and maps every non-wildcard key to itself.
`process_rules()` keeps a local `$parsed_by_source` map:

- first concrete key of a source → `RuleFactory::make(...)`, stored, and assigned as-is;
- every later key of the same source → `array_map(fn ($rule) => clone $rule, $stored)`.

*Alternatives:*
- **Memoise by rule string.** Rejected: it only works for string rules, because arrays
  holding objects or closures have no stable key, and it would save nothing over 27 parses.
- **Static cache in `RuleFactory`.** Rejected: `Rule::when()` outcomes depend on the data,
  and in long-running processes it would carry stale state between validators.
- **Re-derive the source from the concrete key.** Rejected: `0` cannot be told apart from
  `*`, and it is fragile under nested wildcards.

### D2. The first key keeps the parsed instances; later keys get clones

Giving the first key the originals keeps today's identity for non-wildcard keys: a rule object
passed for a plain key is still the same object in `get_rules()`. A shallow clone is enough (see
Context) and skips the constructor, so its message lookups do not run again. Closures are
cloned too; `clone $closure` is valid on PHP 7.4.

*Alternative:* share one set of instances across all keys. Rejected in grilling: siblings
would become aliases of each other through the public `get_rules()`.

### D3. Validator stores its outcome in a nullable flag

Add `protected ?bool $passed = null;`. Typed properties already appear in `Validator`, so
this matches the class.

- `passes()`: if `$this->passed !== null`, return it. Otherwise run the loop as today and set
  `$this->passed = empty($this->errors)`.
- `fails()`: unchanged (`!$this->passes()`), so it inherits the cache.
- `validated()`: replace "run `passes()` if errors are empty, then check errors" with "throw
  if `!$this->passes()`". `validate()` needs no change.
- New `protected function reset_outcome()` sets `$passed = null` and `$errors = []`.
  `set_value()` always calls it, and `sometimes()` calls it inside its `if`, after the merge.

*Alternative:* a separate `bool $has_run` alongside the errors. Rejected: the nullable flag
holds both "has run" and "result" in one field.

### D4. Tests are behavioural, the benchmark stays out of the suite

The tests go in `tests/Unit/Validation/` and follow the spec scenarios. A closure that counts
its calls proves a single run. `assertNotSame` / `assertEquals` on `get_rules()` prove cloning.
There is no timing assertion; the before/after numbers come from `bench/ValidatorBenchTest.php` in this change and are
recorded when the work is applied.

## Risks / Trade-offs

- [A caller relied on one rule object being shared across wildcard keys, for example by
  mutating it after `make()`] → Unlikely and undocumented. The proposal mentions the change, and
  plain keys keep their identity (D2).
- [A future rule class gains an object-valued property that must not be shared] → Shallow
  clone would share it. Mitigation: a note in the `process_rules()` docblock. A rule like that
  can add `__clone()`.
- [Code that changes `$data` other than through `set_value()` would see a stale outcome] →
  `$data` is protected, and `set_value()` is the only public mutator. Subclasses that write
  `$this->data` directly must call `reset_outcome()`, which its docblock says.
- [`stop_on_first_failure` changes after a run would be ignored] → It has no public setter
  today, so this cannot happen. Revisit if one is added.

## Migration Plan

Internal change with no signature changes; ships in the next framework release. Rollback is a
revert of the two files.

## Benchmark

`XDEBUG_MODE=off composer test:unit -- openspec/changes/validator-parse-once/bench/ValidatorBenchTest.php`
(PHP 8.4, 180 variants × 26 fields = 4,681 keys).

Baseline gate: `composer test:unit` OK (953 tests, 2252 assertions). `composer phpcs` exits 1 on three
pre-existing line-length **warnings** (`src/View/TemplateEngine.php:276,282`,
`src/Validation/ValidationRule.php:396`), 0 errors — not touched by this change.

| Step | Before | After |
|---|---|---|
| `Validator::make()` (parse) | 510.7 ms | 8.7 ms |
| `passes()` | 176.1 ms | 165.7 ms (unchanged: per-key message lookups, out of scope) |
| `validated()` after `passes()` | 170.6 ms | 4.3 ms (builds the result only) |
