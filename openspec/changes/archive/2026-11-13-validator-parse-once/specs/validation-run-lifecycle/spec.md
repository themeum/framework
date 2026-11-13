## Purpose

Defines when a validator actually runs its rules, when it reuses the outcome of an earlier run, and which
calls invalidate that outcome, so that every rule runs once per run and errors are never reported twice.

## ADDED Requirements

### Requirement: A validator runs its rules once per state
A validator SHALL run its rules the first time its outcome is needed, by `passes()`, `fails()`,
`validate()` or `validated()`. Later calls SHALL reuse the stored outcome and errors, and SHALL NOT run
the rules again until the stored outcome is invalidated.

#### Scenario: validate() runs each rule once
- **WHEN** a closure rule that counts its calls is validated through `validate()` on data that passes
- **THEN** the closure is called once per key, and `validate()` returns the validated data

#### Scenario: passes() followed by validated()
- **WHEN** `passes()` returns `true` and `validated()` is called afterwards
- **THEN** the rules are not run a second time, and `validated()` returns the validated data

#### Scenario: Repeat calls do not duplicate errors
- **WHEN** `fails()` is called on invalid data and then `passes()` is called on the same validator
- **THEN** `passes()` returns `false` and `errors()` holds each error message once, as it did after `fails()`

### Requirement: Changing rules or data invalidates the outcome
A call that changes what a validator checks SHALL clear the stored outcome and errors, so the next
`passes()`, `fails()`, `validate()` or `validated()` runs the rules again against the current rules and
data. `set_value()` SHALL always invalidate. `sometimes()` SHALL invalidate only when its callback
returns true and rules are added.

#### Scenario: sometimes() adds a rule after a run
- **WHEN** a validator passes, then `sometimes()` adds a rule that the data violates, and `passes()` is called again
- **THEN** `passes()` returns `false` and `errors()` contains the new rule's error

#### Scenario: sometimes() with a false callback
- **WHEN** a validator has run and `sometimes()` is called with a callback that returns false
- **THEN** the stored outcome is kept and the rules are not run again by the next `passes()`

#### Scenario: set_value() after a failed run
- **WHEN** a validator fails because a required field is empty, then `set_value()` fills that field, and `passes()` is called again
- **THEN** `passes()` returns `true` and `errors()` is empty
