## Context

See proposal.md (Why) for the measurements.

How messages flow today:

- `ValidationRule::__construct()` calls `process_default_messages($this->constraints)`. For
  `default` and each supported constraint, it takes the custom message if there is one
  (`get_custom_message()`, which reads `$this->custom_messages` at `"$name.$key"`), otherwise
  `message('validator.<rule>.<key>')`. The result goes into `$this->default_messages`, which is
  a plain `protected array`. About 21 call sites in `Rules/` read `$this->default_messages[$key]`
  directly, and a consumer's own rule subclass may do the same.
- `Validator::apply()` → `sync_custom_messages($this->custom_messages)` →
  `sync_default_messages_with_custom_messages()` → `process_default_messages()` again, for
  every rule on every key. This has to stay per key **when there are custom messages**, because
  `get_custom_message()` uses `$this->name`, and a closure message also gets `$this->value` and
  `$this->data`.
- `message()` → `app()->make(MessagesBag::class)->get()` → `Arr::get($this->messages(), …)`.
  `messages()` rebuilds `defaults()` (a large array literal, not cached while opcache is off) and
  merges in `config('messages')`. `config()` already caches each file in a function-static
  `$cache` for the request.

## Goals / Non-Goals

**Goals:**
- No message-table lookups while checking keys when the validator has no custom messages.
- Cheaper lookups everywhere else.
- Byte-for-byte the same messages as today in every case (spec: validation-messages).

**Non-Goals:**
- Lazy default messages. They would need `$default_messages` to become an accessor or
  `ArrayAccess` object, which breaks subclasses that read the array. Not needed for the
  numbers above.
- Per-field indexing of custom messages to skip unrelated fields.
- Changes to `Validator`, `RuleParser`, `RuleFactory` or the rule classes.

## Decisions

### D1. `MessagesBag::get()` reads `$this->messages`

Replace `Arr::get($this->messages(), $key, '')` with `Arr::get($this->messages, $key, '')`. The
constructor already fills `$this->messages`, and the bag is a container singleton. Its only
dynamic input, `config('messages')`, is cached per request by `config()`, so a lookup cannot
return a different message than it does today within one request. In tests, `TestCase` resets the
container, so each test gets a fresh bag.

*Alternative:* keep `messages()` but memoise it. Rejected: that duplicates what the constructor
already does.

### D2. `sync_custom_messages()` returns early when no custom messages are involved

```php
if ($messages === [] && $this->custom_messages === []) {
    return $this;
}
```

Why this is exact:
- With `$this->custom_messages === []`, every `get_custom_message()` returns `null` whatever
  `$this->name` or `$this->value` is, so `process_default_messages()` would produce only the
  `message()` defaults.
- Those defaults are what `$default_messages` already holds. The constructor computed them with
  empty custom messages, and clones copy them (`validator-parse-once`).
- The `$this->custom_messages === []` half covers a rule object that carried custom messages from
  an earlier validator. It recomputes once and drops back to defaults (spec: shared rule object).

The guard goes in `sync_custom_messages()`, not `sync_default_messages_with_custom_messages()`,
so a subclass that overrides the latter still behaves the same whenever custom messages exist.

*Alternatives:*
- **Lazy `$default_messages`.** Rejected (see Non-Goals).
- **Per-class static cache of defaults.** Rejected: it would survive container resets in tests
  and long-running processes, and D2 already removes the per-key lookups.

### D3. Counting test double for "no lookups"

`tests/Support/Validation/CountingMessagesBag.php` extends `MessagesBag`, counts `get()` calls, and
is bound as the `MessagesBag` singleton inside the test. The "no lookups" scenario asserts the
count stays 0 during `passes()` on a passing payload. Custom-message tests are written first and
must pass on today's code. They lock in current behaviour, because none exist yet.

## Risks / Trade-offs

- [A consumer rule subclass overrides `get_default_message()` or `process_default_messages()` to
  depend on `$this->name`, `$this->value` or `$this->data`] → With no custom messages, its
  defaults would no longer be recomputed per key. Mitigation: document this in the
  `sync_custom_messages()` docblock. Such a rule can override `sync_custom_messages()`. No rule
  in `src/` does this.
- [Code changes `config('messages')` partway through a request] → Not possible today because
  `config()` caches. Unchanged by D1.

## Migration Plan

No signature changes; ships with the next release. Rollback is a revert of two lines and a guard.

## Benchmark

`XDEBUG_MODE=off composer test:unit -- openspec/changes/validator-parse-once/bench/ValidatorBenchTest.php`
plus the stress payload from `example/app/Services/ValidationStressService.php` (180 variants).

| `passes()` (stress payload) | Before | After |
|---|---|---|
| CLI, Xdebug off | 187 ms | 12 ms |
| CLI, Xdebug on | 460 ms | 28 ms |
| Web, playground | 1,238 ms | _pending: re-run `GET /validation-stress?variants=180`_ |
