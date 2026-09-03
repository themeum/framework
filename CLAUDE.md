# Project Instructions for Claude

Section 1 mirrors `.cursor/rules/karpathy-guidelines.mdc`. The PHP standards in
section 3 have been **re-derived from the actual code in `src/` and from the
custom PHPCS sniffs in `phpcs/Framework/Sniffs/`**, not copied from
`.cursor/rules/php-standards.mdc` — that file is stale (it still tells you to
read the version from `kirki-ecommerce.php`, a different project, and it omits
the file-header and ABSPATH rules this repo actually enforces). The sniffs are
the authority: if a rule here and a sniff disagree, the sniff wins and this file
should be corrected.

---

## 0. What this repository is

`themeum/framework` — a standalone PHP framework for WordPress plugins,
distributed as a Composer library and consumed by plugins (Kirki, Kirki
Ecommerce, GrowFund). It is the upstream package, not an application.

Two consequences that shape everything below:

- **It has no application of its own.** The public API surface is what
  consuming plugins call, so backwards compatibility matters more than it
  would in a plugin. Prefer additive changes.
- **It is scoped on release.** Consumers run `php-scoper` over this tree into
  a prefixed `Themeum\Framework\` namespace. Avoid anything that breaks under
  prefixing: no dynamic class-name string building, no `\Framework\...`
  literals in strings where an FQCN constant would do.

`example/` is a local WordPress playground plugin used to exercise the library
end to end. It is **not** library code — don't apply the library's conventions
to it reflexively, and don't ship changes there as if they were the feature.

---

## 1. Behavioral Guidelines (always apply)

Source: `.cursor/rules/karpathy-guidelines.mdc`

Behavioral guidelines to reduce common LLM coding mistakes.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

### Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them — don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

### Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

### Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it — don't delete it.

When your changes create orphans:

- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: every changed line should trace directly to the user's request.

### Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:

```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

---

## 1a. Planning Workflow

When entering plan mode in this project, always use the **OpenSpec workflow**
instead of writing a freeform plan. Reach for the `openspec-*` / `opsx:*`
skills:

- `opsx:explore` — think through the problem before committing to a change
- `opsx:propose` — generate a full proposal (spec deltas, design, tasks)
- `opsx:apply` — implement tasks from an existing change
- `opsx:sync` — sync delta specs into main specs
- `opsx:archive` — finalize and archive a completed change

Before implementing tasks from an existing change, always ask me to run
`opsx:apply` manually by myself instead of applying automatically.

Artifacts live in `openspec/changes/<change-id>/`. Note that `openspec/` is
**gitignored** — it never shows up in `git status`, so don't conclude from a
clean status that no proposal exists. Check the directory.

---

## 2. Verification

The two commands that gate every change:

```bash
composer phpcs && composer test:unit
```

**Always run these through Composer, never the bare binaries.** Both wrappers
exist for a reason:

- `composer test:unit` adds `--prepend tests/prepend.php`, which defines
  `ABSPATH` before anything loads. A bare `vendor/bin/phpunit` produces no
  useful output.
- `composer phpcs` runs `scripts/run-phpcs.php` with `-d auto_prepend_file=`
  and injects `--standard=phpcs.xml.dist`.

Other commands:

| Command | What it does |
|---|---|
| `composer test` | Full suite (currently the same as `test:unit`) |
| `composer test:unit -- --filter 'SomeTest'` | Narrow to one test class |
| `composer phpcbf` | Auto-fix what PHPCS can fix |
| `composer phpcs:fix` | The project's wider fixer script |
| `make up` / `make init` | Bring up the Docker WordPress playground |
| `make test:unit` | Run the suite inside the php container |
| `make wp CMD="plugin list"` | WP-CLI inside the container |

`phpstan.neon.dist` sets level 5 over `src/`, but **phpstan is not in
`require-dev`** — don't install it unasked; say so and let me decide.

Do not use browser-preview / dev-server tooling to verify anything here. There
is no frontend and no dev server. Static checks plus the unit suite are the
verification; anything needing a real WordPress request goes through the Docker
playground and `curl`, and if a change genuinely needs a human to look at it,
say so and let me check.

---

## 3. PHP Coding Standards

Target PHP **7.4** (`composer.json` `config.platform.php`). PSR-12 plus the
custom `Framework` standard, enforced by `phpcs.xml.dist`.

**PHPCS scans `src/` only** — with `src/helpers.php`, `src/Polyfill/`, and
`src/Console/stubs/` excluded. `tests/` and `example/` are unchecked, which
means style there is a matter of matching neighbours, not of a passing build.
Don't take unchecked code as licence to diverge, but do note that `tests/`
legitimately uses a lighter style (see section 5).

### File header — order is enforced

`Framework.Files.FileDeclarationOrder` and `Framework.Commenting.FileDocblock`
require exactly this shape in any file declaring a class, interface, or trait:

```php
<?php
/**
 * One or two sentences on what this file is and why it exists.
 * Say what is non-obvious — the design constraint, the reason it is separate
 * from its neighbour — not what the class name already says.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache;

defined('ABSPATH') || exit;

use Closure;
use Framework\Contracts\Store;
use InvalidArgumentException;

use function Framework\config;
```

- The file docblock comes **before** `namespace`, and must have a description,
  `@package`, and `@since`.
- `@subpackage` is **required** when the namespace is more than two segments
  deep (`Framework\Cache` needs none; `Framework\Cache\Stores` does).
- Tag values are aligned in the file docblock (`@package    Framework`).
- `defined('ABSPATH') || exit;` comes immediately after the namespace. Missing
  it is a PHPCS error.
- `use function` imports go in their own group after the class imports.

### Classes and files

- Class names: **PascalCase**. One class per file, filename matches, PSR-4
  (`Framework\` → `src/`, `Framework\Tests\` → `tests/`).
- **Never `final`** — zero occurrences in `src/`, and consumers subclass freely.
- Interfaces live in `Framework\Contracts` (`src/Contracts/`).
- Traits live in `Framework\Concerns` or `Framework\Supports\Traits`.
- Facades live in `Framework\Supports\Facades`.
- Exceptions live in `Framework\Exceptions`.

### Methods, properties, variables

- Methods and variables: **snake_case** (`get_cart`, `$customer_id`), enforced
  by `Framework.NamingConventions.SnakeCaseMethod` / `SnakeCaseVariable`. The
  only exceptions are methods a native PHP interface mandates
  (`ArrayAccess::offsetGet`, `JsonSerializable::jsonSerialize`, …) and PHPUnit's
  own `setUp`/`tearDown` — keep those camelCase.
- Class constants: **SCREAMING_SNAKE_CASE**, enforced by
  `ScreamingSnakeCaseConstant`.
- **`private` is forbidden**, enforced by `Framework.Visibility.NoPrivate`.
  Use `protected` for internals, `public` for the API surface consuming plugins
  touch. There are zero `private` members in `src/`.
- Names must express intent; no `$a`, `$b`, `$temp`.

### `static::` vs `self::`

Use `static::` — 342 occurrences against 15 for `self::`, and every one of those
15 is somewhere PHP **forbids** `static::` on 7.4: a property default, a default
parameter value, or a constant expression.

```php
// ✅ everywhere else
return static::PAGINATION_LIMIT;

// ✅ the only legitimate self::, because these are constant expressions
protected $match_using = self::MATCH_PATH;
public function for_page($page, $per_page = self::PAGINATION_LIMIT)
```

### Docblocks — required on every method and property

`RequiredMethodDocblock`, `RequiredPropertyDocblock`, `DocblockTagOrder`, and
`DocCommentMissingShort` together mean: a description is mandatory, `@param` is
mandatory when the method takes parameters, `@return` is mandatory always
(`@return void` included, constructors included), and tags must appear in the
order **`@template`, `@param`, `@return`, `@throws`, `@since`**.

Separate tag groups with a blank line — that's the dominant style by a wide
margin (2513 to 6):

```php
/**
 * Attempt to acquire the lock without waiting.
 *
 * A losing insert is not the end of the attempt: the row may belong to a caller
 * that died, so an expired row is removed with a compare and delete.
 *
 * @param string $payload The value to write.
 *
 * @return bool
 *
 * @throws \Framework\Exceptions\QueryException When the statement fails.
 *
 * @since 1.0.0
 */
protected function insert(string $payload)
```

```php
/**
 * The value written for this caller's hold, kept so release can match on it.
 *
 * @var string|null
 *
 * @since 1.0.0
 */
protected $payload;
```

- `{@inheritDoc}` short-circuits the sniff — use it for genuine overrides.
- `@since`: the codebase is overwhelmingly `1.0.0`, with a handful of later
  additions carrying the release they landed in (`2.1.2` in `src/View/`).
  Match the surrounding file; default to `1.0.0`. If you're adding a genuinely
  new public API and think it should carry the upcoming release version, ask.
- Omit `@throws` unless the method really throws.

### Syntax

- Short array syntax `[]`, never `array()`.
- No `declare(strict_types=1)` — zero occurrences; don't introduce it.
- **No Yoda conditions.** Write `if ($count === 0)`, not `if (0 === $count)`.
  Nothing in PHPCS enforces either style and the codebase uses natural order
  throughout.
- Prefer early returns.
- Comments are for *why*, not *what*. Docblocks carry the intent; inline `//`
  comments are rare in `src/` and should stay rare. Don't narrate the code.

### Type hints and return types

Used deliberately, not universally — roughly 64 return types and 100 typed
properties across ~2700 functions. Treat them as encouraged for new code and
**match whatever the surrounding class already does**; don't retrofit types
onto a file that has none.

PHP 7.4 syntax only: no union types, no constructor property promotion, no
enums, no `match`, no named arguments, no nullsafe operator, no `str_contains`
(use the `Framework\Polyfill` helpers where they exist).

### Helpers and facades

Global helpers are **namespaced functions** in `src/helpers.php`, imported
explicitly rather than called globally:

```php
use function Framework\app;
use function Framework\config;
```

When a manager gains a public method, add the matching `@method static` line to
its facade in `src/Supports/Facades/` — the facade docblock is the IDE contract
and it goes stale silently otherwise.

### WordPress

- Escape, sanitize, and internationalize per WordPress conventions at the
  boundaries.
- Prefer the framework's own seams over raw WordPress globals. In particular,
  database access goes through the `DB` facade / `Database\Connection\Connection`,
  **not** `global $wpdb` — going through the facade keeps the query log and the
  error handling. Raw SQL is fine where the reason is real (the options cache
  would defeat an atomic lock, for instance); reaching past `DB::` is not.

---

## 4. Where code goes

| Path | Contents |
|---|---|
| `src/Contracts/` | Interfaces |
| `src/Concerns/`, `src/Supports/Traits/` | Traits |
| `src/Supports/Facades/` | Static facade proxies |
| `src/Exceptions/` | Exception classes |
| `src/Middlewares/` | HTTP middleware |
| `src/<Subsystem>/` | A cohesive subsystem (`Cache/`, `Database/`, `Session/`, `RateLimiting/`, `View/`, `Http/`) with its own `ServiceProvider` where it needs boot-time hooks |
| `tests/Unit/<Subsystem>/` | Tests, mirroring the `src/` layout |
| `tests/Support/` | Test doubles and stubs, mirroring the same layout |
| `docs/` | User-facing feature documentation |
| `example/` | The playground plugin — not library code |

Put new code beside its peers rather than inventing a new top-level directory.

**Never hand-edit:** `vendor/`, `example/vendor/`, `example/libraries/`. The
last is a scoped copy of this very library, regenerated by `make scope`.

---

## 5. Tests

PHPUnit 9 with the Yoast polyfills, bootstrapped by `tests/Unit/bootstrap.php`.
There is no WordPress in the test process — `tests/Support/StubsWordPressFunctions.php`
stands in for the WordPress functions the library calls, and `tests/Support/`
holds hand-written doubles (`TestWpdb`, `FrozenArrayStore`, `FrozenRateLimiter`).

Conventions, all of which differ from `src/` because PHPCS doesn't reach here:

- Test classes extend `Framework\Tests\Unit\TestCase`, which resets the
  container, `Str` macros, the wpdb double, route state, and the facade cache
  in `tearDown()`. A subsystem usually adds its own base
  (`CacheTestCase`, `RateLimiterTestCase`) for shared setup.
- Test methods: `test_snake_case_describing_the_behaviour()`.
- **No docblocks, no ABSPATH guard, no file header** in test files.
- Typed properties and `: void` return types are used freely here.
- Time-dependent behaviour is tested through a frozen clock double
  (`->freeze($now)` / `->travel($seconds)`), never `sleep()`.

When you add a WordPress function stub, add it to
`StubsWordPressFunctions.php` rather than defining it inline in a test.

Write the test that would have caught the bug. A change to `src/` that adds
behaviour and no test is incomplete unless I said otherwise.

---

## 6. Documentation

User-facing features get a `docs/<feature>.md`, structured like
[`docs/cache.md`](docs/cache.md): a table of contents, then numbered `## N. Topic`
sections, quick start first, configuration and drivers in the middle, and — for
anything modelled on a Laravel API — a **"Where this differs from Laravel"**
section near the end that is honest about the gaps. That section is not
optional; it is what stops a consumer assuming parity we don't have.

Keep docs in the same change as the code. A doc that describes the old
behaviour is worse than no doc.

---

## 7. Commits

Don't commit or push unless I ask. When I do ask commit the changes with a inferred
commit message that is good enough for PR title and description and also push on behalf of me.
