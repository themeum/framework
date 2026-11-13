## Purpose

Defines how a validator turns its rules array into rule instances when a rule key uses `*` wildcards,
so that large payloads cost one parse per rule entry rather than one per concrete key.

## Requirements

### Requirement: Wildcard keys expand against the data
A rule key containing `*` SHALL expand into one concrete dot-notated key for every matching path in
the data being validated. A wildcard that matches no data SHALL produce no keys. A key without `*`
SHALL be kept as given.

#### Scenario: Wildcard expands per item
- **WHEN** the data holds three `variants` items and the rules contain `variants.*.price => 'required|numeric'`
- **THEN** the validator's rules contain `variants.0.price`, `variants.1.price` and `variants.2.price`

#### Scenario: Wildcard over an empty or missing array
- **WHEN** the rules contain `variants.*.price` and the data has no `variants` items
- **THEN** no `variants.*.price` key is produced

### Requirement: Each rule entry is parsed once per validator
The validator SHALL turn each entry of the rules array into rule instances once, however many concrete
keys that entry's wildcard expands to. The parsed result SHALL be valid only for the validator that
produced it, and SHALL NOT be reused by another validator instance.

#### Scenario: Large wildcard payload
- **WHEN** a validator is made for 180 `variants` items with 26 `variants.*.<field>` rule entries
- **THEN** the rules are parsed 27 times in total (one per entry, including `variants`), not once per each of the 4,681 expanded keys, and every expanded key carries the rules of its entry

#### Scenario: Conditional rule outcome is not reused across validators
- **WHEN** two validators are made with the same `Rule::when()` wildcard rule but data that makes the condition true for one and false for the other
- **THEN** each validator applies the branch its own data selects

### Requirement: Each expanded key owns its rule instances
Every concrete key produced from one wildcard entry SHALL hold its own rule instances. Changing a
rule instance obtained for one key SHALL NOT change the rules of any other key. This SHALL hold for
string rules, rule objects passed by the caller, closures, and the rules that `Rule::when()` /
`Rule::unless()` select.

#### Scenario: String rule instances are distinct per key
- **WHEN** `variants.*.sku => 'required|string|max:50'` expands to `variants.0.sku` and `variants.1.sku`
- **THEN** the rule instances for the two keys are equal in type and constraints but are not the same objects

#### Scenario: Caller-supplied rule object is distinct per key
- **WHEN** the rules contain `variants.*.sku => [Rule::string()]` and the data holds two items
- **THEN** the two expanded keys hold different `StringRule` instances, and adding a constraint to one key's instance leaves the other key's instance unchanged

#### Scenario: Closure rule runs for every key
- **WHEN** the rules contain `variants.*.sku => [function ($value) { ... }]` and the data holds three items
- **THEN** the closure is called once for each of the three keys, with that key's value

### Requirement: Validation results are unchanged by parsing once
Parsing once SHALL NOT change which keys pass or fail, the error messages reported for each key, or
the data returned by `validated()`, compared with parsing every expanded key separately.

#### Scenario: Errors are reported per expanded key
- **WHEN** `variants.*.price => 'required|numeric|min:0'` is validated against items whose prices are `5`, `-1` and `'abc'`
- **THEN** `variants.0.price` has no error, and `variants.1.price` and `variants.2.price` each carry their own error messages
