## Purpose

Defines how a route builds the controller instance for a controller action, so that controller
constructors receive dependencies under the same rules as action-method parameters and every other
service the application container resolves.

## ADDED Requirements

### Requirement: Controllers are resolved through the application container
When a route with a `[Controller::class, 'method']` action is dispatched, on a REST or a site route,
the framework SHALL obtain the controller instance from the application container. The controller's
constructor dependencies SHALL be resolved by the same rules as `app()->make()`. A dependency that
is bound or registered as a singleton SHALL receive the bound instance, and an interface with a
binding SHALL receive the bound implementation.

#### Scenario: Constructor and action method share a container singleton
- **WHEN** a class is registered as a singleton in the container, and a controller injects that class both in its constructor and in the dispatched action method
- **THEN** the constructor and the action method receive the same instance, and it is the container's singleton

#### Scenario: Constructor receives a bound interface
- **WHEN** a controller constructor type-hints an interface that has a binding in the container
- **THEN** the controller is built with the bound implementation, and no "class does not exist" error is raised

#### Scenario: Constructor receives a container-built service
- **WHEN** a controller constructor type-hints a class that is bound with a factory closure, whose own constructor takes non-class arguments supplied by that closure
- **THEN** the controller is built with the instance the closure returns

### Requirement: Scalar constructor parameters with defaults are accepted
A controller constructor parameter with a built-in type, or with no type, SHALL NOT cause
resolution to fail when it declares a default value. The default value SHALL be passed. A
parameter of that kind with no default SHALL fail with the container's error for an unresolvable
primitive.

#### Scenario: Scalar parameter with a default
- **WHEN** a controller constructor is `__construct(Logger $logger, int $limit = 10)`
- **THEN** the controller is built with a resolved `Logger` and `$limit` equal to `10`

### Requirement: A controller is built fresh for each dispatch
The framework SHALL NOT keep its own cache of controller instances or of their constructor
dependencies. Each dispatch SHALL obtain the controller from the container. That SHALL yield a new
instance unless the consumer bound the controller, or the dependency, as a singleton. Two different
controllers SHALL share an unbound dependency only if the container would share it.

#### Scenario: Two dispatches build two controllers
- **WHEN** the same controller route is dispatched twice in one PHP process, and the controller is not bound as a singleton
- **THEN** each dispatch receives a distinct controller instance

#### Scenario: Singleton-bound controller is reused
- **WHEN** a controller class is registered as a singleton in the container, and its route is dispatched twice in one PHP process
- **THEN** both dispatches receive the same controller instance

#### Scenario: Unbound dependency is not shared across controllers
- **WHEN** two different controllers each inject the same unbound concrete service in their constructors
- **THEN** each controller receives its own instance of that service

### Requirement: Circular constructor dependencies are reported
Resolving a controller whose constructor dependencies form a cycle SHALL fail with an exception
whose message identifies the circular dependency. It SHALL NOT recurse without limit.

#### Scenario: Circular dependency in a controller's constructor graph
- **WHEN** a controller depends on class A, A depends on B, and B depends on A
- **THEN** resolution throws an exception whose message contains "Circular dependency detected"
