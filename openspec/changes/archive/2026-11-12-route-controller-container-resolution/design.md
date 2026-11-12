## Context

See proposal.md (Why) for the defect. The relevant code paths are:

- `Route::resolve_controller()` (`src/Route.php:1898`) is the only place that builds a controller. It runs once per dispatch, from `dispatch_with_request()`, for both REST and site routes. The permission callback and the middleware pipeline never build the controller.
- `Route::make()` (`src/Route.php:1198`) is a reflection injector with its own circular-dependency check. It memoizes every instance it builds, controllers and their dependencies alike, in `protected static $instances` through `cache()`, `is_cached()` and `get_cached()`.
- `Route::resolve_abstracts()` (`src/Route.php:1478`) resolves action-method dependencies through `app()->make()`.
- `Container::autowire()` (`src/Container.php`) already does everything `Route::make()` does, plus the parts it gets wrong: bindings, singletons, aliases, scalar defaults, a "not instantiable" check, and circular-dependency detection with the full chain in the message.

## Goals / Non-Goals

**Goals:**

- Make the container the single dependency injector for controller construction.
- Keep `Route::make()` callable and overridable by subclasses with an unchanged signature.

**Non-Goals:**

- Changing how `Container` resolves dependencies. In particular, `?SomeInterface $x = null` with an unbound interface still throws instead of receiving `null`. That happened under `Route::make()` too, so this change makes nothing worse. Fixing it changes resolution for every `app()->make()` caller and belongs in its own change.
- Memoizing controllers per `Route` object, which is the Laravel approach. Nothing here needs it; see Decision 1.
- Changing how action-method or closure dependencies are categorized or resolved.

## Decisions

### 1. Controllers are built fresh per dispatch, not cached

`resolve_controller()` gets the controller from the container on every dispatch. A consumer who wants one shared instance binds the controller, or the dependency, with `app()->singleton()`.

**Why:** the static cache never saves work inside a single request, because the controller is built exactly once per dispatch either way. A process sees more than one dispatch only for REST `batch/v1`, `rest_do_request()`, and block-editor preloads. There the static cache is a hazard, because any per-request state on the controller would leak into the next sub-request. kirki-ecommerce's 28 controllers write properties only in their constructors, so a fresh instance behaves identically for them. The cache also forces `TestCase::reset_route_state()` to clear `Route::$instances` between tests.

**Alternatives considered:**

- *Memoize on the `Route` instance (Laravel).* This adds state and a second lifetime rule for no current benefit.
- *Keep the process-wide static cache, filled by the container.* This keeps the cross-controller sharing (symptom 4) and the hidden second lifetime the report complains about.

### 2. `Route::make()` becomes a one-line call to the container; the cache members are deleted

```php
protected function make(string $abstract, array $resolving = [])
{
    return app()->make($abstract);
}
```

`$resolving` stays in the signature so that a subclass that overrides or calls `make()` keeps a compatible signature. It goes unused, and the docblock says it is kept for compatibility. `resolve_controller()` keeps calling `$this->make($controller)`, so `make()` stays the one method a subclass overrides to change how controllers are built.

`protected static $instances`, `cache()`, `is_cached()` and `get_cached()` are deleted. With nothing filling the cache they would be dead code, and keeping them invites someone to wire the second cache back in. The `use ReflectionClass;` import becomes unused and is removed. `Exception`, `throw_if` and `throw_unless` are still used elsewhere in `Route.php` and stay.

**Alternatives considered:**

- *Delete `make()` too.* Any subclass that calls `$this->make()` would hit a fatal error. The one-line method is cheap compatibility.
- *Keep everything and mark it deprecated.* This leaves the broken injector reachable, and a deprecated cache that nothing fills would be misleading.

### 3. The existing `class_exists($controller)` guard stays

`resolve_controller()` already throws `InvalidRoutActionException('Controller X not found')` before calling `make()`. That message is more useful at the routing layer than the container's reflection error, so it stays unchanged.

### 4. Tests

- Replace `test_make_resolves_constructor_dependencies_and_caches_instances` with a test that two `make()` calls return two distinct instances. The old test asserts the behaviour being removed.
- Add cases for each spec scenario: a constructor and action method that share a singleton, a bound interface, a closure-built service with a scalar constructor, a scalar default, a singleton-bound controller reused across two dispatches, and an unbound dependency that is not shared across controllers.
- `test_make_detects_circular_dependencies` keeps working unchanged. The container's message starts with `Circular dependency detected:`.
- Remove the `'instances' => []` entry from `TestCase::reset_route_state()`. The `hasProperty()` guard would skip it anyway, but it would be a stale reference.

## Risks / Trade-offs

- [A consumer subclass reads `Route::$instances` or calls the cache helpers] → It fails loudly with an undefined property or method, not silently. No known consumer does this. The CHANGELOG entry names the removed members.
- [A consumer controller relied on state persisting across dispatches in one process] → It only matters for batch or internal REST dispatch. The fix is to bind the controller as a singleton, and the CHANGELOG entry says so. kirki-ecommerce controllers are stateless.
- [Unbound services that were accidentally shared between controllers are now separate] → This is the intended fix (symptom 4). Anything that must be shared should be a container singleton.
- [Error messages change for bad constructors: "not instantiable" instead of "is abstract" / "does not exist"] → Only error text changes, and the container's messages are more accurate.

## Migration Plan

Ships as 3.3.2 under `### Fixed`. Consumers update the Composer dependency and re-scope. No code change is needed unless a subclass touches the removed cache members. Rollback means reverting to 3.3.1.
