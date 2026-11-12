## Why

`Route::make()` (`src/Route.php:1198`) builds controllers with its own reflection injector and a process-wide static cache. It never asks the application container. Action-method parameters, by contrast, go through `app()->make()`. So a controller's constructor follows different rules from everything else in the framework:

- A container singleton injected into the constructor is a different object from the same singleton injected into the action method. Nothing errors, so the bug is silent.
- An interface bound in the container fails with `Class "X" does not exist`.
- A scalar parameter fails with `must be a class type, not a built-in type`, even when it has a default value.
- Unbound services are shared between unrelated controllers through `Route::$instances`.

All four were reproduced against the current code. kirki-ecommerce is one step away from hitting this. `ShippingService` is a container singleton built from `array $shipping_settings`, and `OrderCalculationContextService` injects it. Any controller constructor that reaches it would crash.

## What Changes

- Controllers are built by the application container on every dispatch, so constructor dependencies follow the same rules as `app()->make()`: bindings, singletons, bound interfaces, scalar defaults, and circular-dependency detection.
- `Route::make()` keeps its signature and delegates to `app()->make()`. `resolve_controller()` keeps calling `$this->make()`, so subclasses still have one method to override.
- **BREAKING (narrow):** the protected static cache is removed: `Route::$instances`, `cache()`, `is_cached()`, `get_cached()`. Only a subclass of `Route` that touches these members is affected.
- Behaviour change: a controller is no longer reused for the whole process. It is built fresh per dispatch, and so are its unbound dependencies. Consumers who want one shared instance bind it as a singleton.
- CHANGELOG `## [3.3.2]` → `### Fixed` entry; a "Controller constructor injection" subsection in `docs/routing-examples.md`.

## Capabilities

### New Capabilities

- `route-controller-resolution`: how a route builds its controller instance: through the application container, fresh per dispatch, with the same resolution rules as action-method dependencies.

### Modified Capabilities

None. No existing spec covers routing.

## Impact

- **Code:** `src/Route.php`: `make()`, the static `$instances` property, and the `cache()`, `is_cached()` and `get_cached()` methods.
- **Tests:** `tests/Unit/RouteDiTest.php` (replace the caching test, add container-resolution cases); `tests/Unit/TestCase.php` (drop the `'instances'` reset).
- **Docs:** `docs/routing-examples.md`, `CHANGELOG.md`.
- **Consumers:** kirki-ecommerce controllers need no change. They inject only concrete services and keep no state between dispatches. Controller constructors become safe for container-bound services such as `ShippingService`, `MoneyManager` and `PaymentManager`.
- **Scoping:** none. `app()->make()` takes the class-string it is given, so nothing new is built from string literals.
