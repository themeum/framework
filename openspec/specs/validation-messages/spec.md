## Purpose

Defines which message a failing validation rule reports, whether it comes from the caller's custom messages
or the framework's message table, and that values which pass do not pay for looking up messages.

## Requirements

### Requirement: Custom messages override the defaults per field
When a validator is given custom messages, a failing rule SHALL report the custom message registered for
that field and rule (`<field>.<rule>`) or for that field and constraint (`<field>.<constraint>`). Keys
may be written flat (`'name.required'`) or nested (`['name' => ['required' => ...]]`). Placeholders in a
custom message SHALL be replaced as in default messages. A closure custom message SHALL be called with
the field name, the value, and the data, and its return value SHALL be reported. A field without a custom
message SHALL report the default message.

#### Scenario: Flat custom message
- **WHEN** `name => 'required'` fails with custom messages `['name.required' => 'Flat']`
- **THEN** the error for `name` is `Flat`

#### Scenario: Nested custom message
- **WHEN** `name => 'required'` fails with custom messages `['name' => ['required' => 'Nested']]`
- **THEN** the error for `name` is `Nested`

#### Scenario: Constraint message with a placeholder
- **WHEN** `name => 'string|max:3'` fails on `'abcdef'` with custom messages `['name.max' => 'At most {max}']`
- **THEN** the error for `name` is `At most 3`

#### Scenario: Closure custom message
- **WHEN** `name => 'string'` fails on `5` with custom message `name.string` set to a closure returning `"Closure {$name}=" . $value`
- **THEN** the error for `name` is `Closure name=5`

#### Scenario: Custom message for one expanded wildcard key
- **WHEN** `v.*.s => 'string|max:3'` fails for both items with custom messages `['v.1.s.max' => 'Second only']`
- **THEN** `v.1.s` reports `Second only`, and `v.0.s` reports the default max message

#### Scenario: Custom message for another field
- **WHEN** `name => 'string|max:3'` fails with custom messages that only contain `other.max`
- **THEN** `name` reports the default max message

### Requirement: A rule reused across validators does not keep custom messages
A rule object passed to more than one validator SHALL report the messages of the validator that is
running it. A validator without custom messages SHALL report default messages even if the same rule
object reported a custom message in an earlier validator.

#### Scenario: Shared rule object, custom then default
- **WHEN** one `Rule::string()->max(3)` object fails in validator A with custom messages `['name.max' => 'Custom A']`, and then fails in validator B without custom messages
- **THEN** A reports `Custom A`, and B reports the default max message

### Requirement: Passing values do not need message lookups
When a validator has no custom messages, checking a value that passes SHALL NOT look up messages in the
message table. Default messages SHALL be looked up when rule instances are built, not again for each
key that is checked.

#### Scenario: Large passing payload without custom messages
- **WHEN** a validator without custom messages checks a passing payload of 5,956 expanded keys
- **THEN** `passes()` makes no message-table lookups, and returns `true`

#### Scenario: Default messages still reported
- **WHEN** a validator without custom messages checks `name => 'required'` against an empty value
- **THEN** the error for `name` is the default required message
