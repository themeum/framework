## Why

After `validator-parse-once`, parsing a 5,956-key payload takes about 10 ms, but `passes()` still takes
180 ms in the CLI, 484 ms with Xdebug on, and 1,238 ms in the playground web request (from
`GET /validation-stress?variants=180`). Almost all of that time goes to message lookups for messages
that are never shown:

- Before every rule check, `Validator::apply()` calls `sync_custom_messages()`, which looks up a
  default message for **every constraint the rule class supports**: 18 for `StringRule`, 10 for
  `NumericRule`. This happens even when the value passes. For the stress payload that is
  **74,357 `message()` calls**.
- Every `message()` call makes `MessagesBag::get()` rebuild its whole message table (the
  `defaults()` literal merged with `config('messages')`). The constructor already built and stored
  that table in `$this->messages`, but `get()` never reads it.

Prototype measured on the stress payload (180 variants, 5,956 keys; the full unit suite passes):

| `passes()` | Today | Prototype |
|---|---|---|
| CLI, Xdebug off | 180 ms | 12 ms |
| CLI, Xdebug on | 484 ms | 29 ms |

## What Changes

- `MessagesBag::get()` reads the table its constructor stored instead of rebuilding it on every
  call. `config()` already caches each config file for the request, so the messages returned
  stay the same.
- `ValidationRule::sync_custom_messages()` skips recomputing the default messages when the
  validator has no custom messages and the rule was not carrying any before. The defaults the
  constructor computed are already the ones that call would produce. Whenever there are custom
  messages, or the rule had custom messages from an earlier sync, it recomputes exactly as today.
- No public signatures change, and `$default_messages` stays a fully filled array, so rule
  subclasses that read it directly keep working. No **BREAKING** change is intended.

Out of scope:
- Speeding up validators that do have custom messages beyond what the `MessagesBag` fix gives.
  They still recompute per key, because a custom message depends on the field name and a
  closure message also gets the value.
- The `invalid_rule` message that `RuleFactory::build_rules_chain()` builds for every rule
  piece while parsing. After `validator-parse-once` this runs only once per rule entry
  (about 100 calls per validator), so it is not worth a change.
- The playground's `XDEBUG_MODE=debug` / `opcache.enable=0` settings, which belong to the
  environment, not the library.

## Capabilities

### New Capabilities
- `validation-messages`: which message a failing rule reports (custom message, closure custom
  message, or the default from the message table), and that a passing value never needs its
  messages looked up.

### Modified Capabilities
<!-- None: no validation-messages spec exists yet. -->

## Impact

- Code: `src/Supports/MessagesBag.php` (`get()`), `src/Validation/ValidationRule.php`
  (`sync_custom_messages()`).
- Unchanged: `Validator`, `RuleParser`, `RuleFactory`, the classes in `src/Validation/Rules/`.
- Tests: new tests for custom messages, which have no coverage today; they must pass before and
  after the change.
- Depends on `validator-parse-once`, which is implemented but not yet archived. Its numbers are
  the "Today" column above.
