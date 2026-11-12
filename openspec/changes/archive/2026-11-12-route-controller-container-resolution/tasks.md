## 1. Failing tests first

- [x] 1.1 In `tests/Unit/RouteDiTest.php`, replace `test_make_resolves_constructor_dependencies_and_caches_instances` with a test that `make()` resolves constructor dependencies and that two calls return two distinct instances. Verify: it fails against the current static cache.
- [x] 1.2 Add a test where a class registered with `app()->singleton()` is injected into both the controller constructor and the action method of a dispatched controller route. Assert that both see the same instance, and that it is `app()->make()`'s instance. Drive the dispatch through `dispatch_site()`, as `tests/Unit/Routing/SiteRouterTest.php` does, or call `resolve_controller()` with a `Request`. Verify: it fails today.
- [x] 1.3 Add a test where the controller constructor type-hints an interface bound with `app()->bind()`, and assert that it receives the bound implementation. Verify: it fails today with `Class "..." does not exist`.
- [x] 1.4 Add a test where the constructor injects a service bound by a factory closure, and that service's own constructor takes an `array` (like kirki-ecommerce's `ShippingService`). Assert that the controller holds the closure's instance. Verify: it fails today with "must be a class type".
- [x] 1.5 Add a test for `__construct(RouteDiLeafService $leaf, int $limit = 10)` that asserts `$limit === 10`. Verify: it fails today with "must be a class type".
- [x] 1.6 Add a test where a controller bound as a singleton yields the same instance from two `make()` calls.
- [x] 1.7 Add a test where two different controllers that inject the same unbound concrete service receive different service instances. Verify: it fails today, because the static cache shares them.
- [x] 1.8 Keep `test_make_detects_circular_dependencies` unchanged. Confirm it still expects "Circular dependency detected".

## 2. Route the controller through the container

- [x] 2.1 In `src/Route.php`, replace the body of `make()` with `return app()->make($abstract);`. Keep the signature and the `$resolving` parameter. Update the docblock to say that it resolves through the application container and that `$resolving` is kept only for signature compatibility. Drop the `@throws \Exception` tag if nothing in the body throws.
- [x] 2.2 Leave `resolve_controller()` calling `$this->make($controller)`, and leave its `class_exists()` guard unchanged.
- [x] 2.3 Delete `protected static $instances`, `cache()`, `is_cached()` and `get_cached()` from `src/Route.php`.
- [x] 2.4 Remove the `use ReflectionClass;` import if nothing else in `Route.php` uses it. Keep `Exception`, `throw_if` and `throw_unless`, which are still used.
- [x] 2.5 Remove `'instances' => []` from `reset_route_state()` in `tests/Unit/TestCase.php`.
- [x] 2.6 Run `composer test:unit -- --filter 'RouteDiTest'` and `composer test:unit -- --filter 'SiteRouterTest'`. Verify: all of section 1 passes.

## 3. Documentation

- [x] 3.1 In `docs/routing-examples.md`, add a "Controller constructor injection" subsection beside the existing controller examples, without adding a new numbered section. Cover: constructors receive container services (bindings, singletons, bound interfaces, scalars with defaults); a controller is built fresh per dispatch; bind it with `app()->singleton()` to share one instance. Update the table of contents if subsections are listed there.
- [x] 3.2 Add `## [3.3.2]` → `### Fixed` to the top of `CHANGELOG.md`, following the 3.3.1 entry's style. State that controller constructors now resolve through the container (bindings, singletons, interfaces, scalar defaults), that controllers are built fresh per dispatch (bind as a singleton to share one), and that the protected `Route::$instances`, `cache()`, `is_cached()` and `get_cached()` were removed.

## 4. Verification

- [x] 4.1 Run `composer phpcs && composer test:unit`. Verify: both pass with no new warnings.
- [x] 4.2 Re-run `/private/tmp/claude-501/-Users-ahamed-development-ollyo-wordpress-framework/b9a69640-08a3-4fdc-9414-fd9e13cf65e3/scratchpad/repro.php`, or its equivalent. Verify: the Cart instances are the same, `PaymentGateway` resolves, and `$limit` defaults to 10.
