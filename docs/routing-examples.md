# Routing Examples

This guide covers every common use case for the unified `Route` API — REST endpoints and front-end site routes share the same `Route::get/post/put/patch/delete` methods.

## Table of contents

1. [Setup](#1-setup)
2. [REST routes](#2-rest-routes)
3. [Site route registration](#3-site-route-registration)
4. [Groups](#4-groups)
5. [Parameters](#5-parameters)
6. [Named routes](#6-named-routes)
7. [Route params in views](#7-route-params-in-views)
8. [Page matching](#8-page-matching)
9. [Dispatch hooks](#9-dispatch-hooks)
10. [Route-level handlers](#10-route-level-handlers)
11. [Controller returns](#11-controller-returns)
12. [View management](#12-view-management)
13. [Middleware, authorize, validate](#13-middleware-authorize-validate)
14. [Closure routes](#14-closure-routes)
15. [Error handling](#15-error-handling)
16. [Template paths](#16-template-paths)

---



## 1. Setup

Configure namespaces, routing method, and view path once during bootstrap (typically in your plugin's routes file or service provider).

```php
use Framework\Route;
use Framework\Wordpress\Constants\HookNames;

use function Framework\app;

// REST API namespace (WordPress register_rest_route)
Route::set_namespace('kirki/v1');

// Short prefix for rewrite query vars and site route IDs
Route::set_site_namespace('kirki');

// How site requests are matched (default: rewrite_rules)
Route::set_routing_method(Route::ROUTING_REWRITE_RULES);
// Or match the request path on parse_request:
// Route::set_routing_method(Route::ROUTING_PARSE_REQUEST);

// Default dispatch hook for site routes (default: template_include)
Route::set_default_hook(HookNames::TEMPLATE_INCLUDE);
// Opt back into early-exit full response control globally:
// Route::set_default_hook(HookNames::TEMPLATE_REDIRECT);

// Application view directory (default: {base_path}/resources/views)
app()->use_view_path(plugin_dir_path(__FILE__) . 'resources/views');

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, function () {
    Route::flush();
});
```

**Expected:** REST routes live under `/wp-json/kirki/v1/...`. Site routes register rewrite rules under the site namespace and resolve views from the configured view path (with theme override support).

---



## 2. REST routes

REST routes are unchanged. They register on `rest_api_init` via `RegisterRestApi`.

```php
use Framework\Route;
use App\Http\Controllers\EventsController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Requests\EventStoreRequest;

Route::get('/events', [EventsController::class, 'index']);

Route::post('/events', [EventsController::class, 'store'])
    ->middleware([AuthMiddleware::class]);

Route::get('/events/{event}', [EventsController::class, 'show']);

Route::put('/events/{id:int}', [EventsController::class, 'update']);

Route::delete('/events/{id}', [EventsController::class, 'destroy'])
    ->where('id', 'int');
```

Controller with DI (model binding, Form Request, services):

```php
public function show(EventShowRequest $request, Event $event, EventService $service)
{
    return response()->json($service->present($event));
}
```

**Expected:** `GET /wp-json/kirki/v1/events/42` resolves `{event}` from the route, authorizes/validates via the Form Request, and returns JSON.

---



## 3. Site route registration

Two ways to mark a route as a front-end (site) route:

### Block wrapper (preferred for groups of site routes)

```php
Route::site(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('products/{id:int}', [ProductController::class, 'show'])
        ->name('products.show');
});
```



### Fluent marker (single route)

PHP cannot expose both `Route::site(Closure)` and `->site()` under the same name, so the fluent marker is `->as_site()`:

```php
Route::get('dashboard', [DashboardController::class, 'index'])
    ->as_site()
    ->name('dashboard');
```

**Expected:** Site routes are skipped by `RegisterRestApi` and booted by `RegisterSiteRoutes` on `init`. Visiting `/dashboard` dispatches the controller through the same DI pipeline as REST.

---



## 4. Groups

`Route::group()` works for both REST and site routes. Nested groups stack prefixes and middleware.

```php
Route::site(function () {
    Route::group(['prefix' => 'shop', 'middleware' => [AuthMiddleware::class]], function () {
        Route::get('products/{id:int}', [ProductController::class, 'show'])
            ->name('products.show');

        Route::group(['prefix' => 'admin'], function () {
            Route::get('orders', [OrderController::class, 'index'])
                ->name('shop.admin.orders');
        });
    });
});
```

**Expected:**

- Products URL: `/shop/products/5`
- Orders URL: `/shop/admin/orders`
- Both routes inherit `AuthMiddleware`

Groups outside a site block apply to REST (or to individually `->as_site()` marked routes):

```php
Route::group(['prefix' => 'v2', 'middleware' => [AuthMiddleware::class]], function () {
    Route::get('/users', [UserController::class, 'index']);
});
```

---



## 5. Parameters



### Inline type syntax

```php
Route::site(function () {
    Route::get('products/{id:int}', [ProductController::class, 'show']);
    Route::get('posts/{slug:slug}', [PostController::class, 'show']);
    Route::get('users/{name:alpha}', [UserController::class, 'show']);
});
```

Supported type keywords include: `int`, `alpha`, `alphanum`, `slug`, and others mapped by `RouteParser`.

### `->where()` overrides

```php
Route::get('products/{id}', [ProductController::class, 'show'])
    ->as_site()
    ->where('id', 'int')
    ->name('products.show');

// Multiple at once
Route::get('posts/{year}/{slug}', [PostController::class, 'show'])
    ->as_site()
    ->where([
        'year' => 'int',
        'slug' => 'slug',
    ]);

// Callable validator
Route::get('coupons/{code}', [CouponController::class, 'show'])
    ->as_site()
    ->where('code', function ($value) {
        return preg_match('/^[A-Z0-9]{6}$/', (string) $value);
    });
```

**Expected:** Invalid params fail matching (404). Valid params are sanitized and injected into the Request / controller.

---



## 6. Named routes

```php
Route::site(function () {
    Route::get('products/{id:int}', [ProductController::class, 'show'])
        ->name('products.show');
});

// Generate a URL
$url = Route::site_url('products.show', ['id' => 5]);
// → https://example.com/products/5
```

Active navigation in a template:

```php
<a href="<?php echo esc_url(Route::site_url('products.show', ['id' => $id])); ?>"
   class="<?php echo esc_attr(Route::is('products.show') ? 'is-active' : ''); ?>">
    Product
</a>
```

**Expected:** `Route::is('products.show')` is `true` only while that named site route is being dispatched.

---



## 7. Route params in views

During site route dispatch, matched params (plus any `->with()` data) are available via helpers:

```php
// In a controller or view
$id = Route::route_param('id');
$all = Route::route_params();

// With defaults
$slug = Route::route_param('slug', 'default-slug');
```

```php
<!-- resources/views/shop/product.php -->
<p>Product ID: <?php echo esc_html(Route::route_param('id')); ?></p>
```

**Expected:** Params reflect the matched rewrite query vars for the current request. Outside a dispatch, helpers return defaults / empty.

---



## 8. Page matching

Match an existing WordPress Page by path/slug instead of a custom rewrite path:

```php
Route::site(function () {
    Route::get('about-us', [PageController::class, 'about'])
        ->match_page()
        ->name('pages.about');
});
```

**Expected:** When the WordPress Page whose path is `about-us` is viewed, the site route controller runs. Path matching still uses `MATCH_PATH` (the default) for normal rewrite URLs.

---



## 9. Dispatch hooks

Control which WordPress hook dispatches the route with fluent aliases (preferred) or the generic `hook()` method.

**Default:** `template_include` — WordPress loads the returned template path through the normal theme template flow (better core/plugin compatibility).

```php
Route::site(function () {
    // Default: template_include — return view('…', $data); use view_data() in the template
    Route::get('shop/products/{id:int}', [ProductController::class, 'show'])
        ->name('products.show');

    // Opt into template_redirect for full response control (JSON, redirects, early exit)
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->template_redirect()
        ->name('dashboard');

    // Explicit template_include with custom priority
    Route::get('legacy-report', [ReportController::class, 'show'])
        ->template_include(20)
        ->name('reports.legacy');
});
```

Equivalent using `hook()`:

```php
use Framework\Wordpress\Constants\HookNames;
54erd
Route::get('dashboard', [DashboardController::class, 'index'])
    ->hook(HookNames::TEMPLATE_REDIRECT);

Route::get('legacy-report', [ReportController::class, 'show'])
    ->hook(HookNames::TEMPLATE_INCLUDE, 20);
```

**Expected:**

- Default / `template_include()` / `hook(TEMPLATE_INCLUDE)` — controller returns `view('name', $data)` (or a filesystem path). Layout-enabled views load a framework layout wrapper (theme header/footer). Partial views return the raw path.
- `template_redirect()` / `hook(TEMPLATE_REDIRECT)` — SiteRouter sends the response (view HTML via `TemplateEngine::render()`, redirect, JSON, or string) and exits.
- Both aliases accept an optional WordPress hook `$priority` (default `10`).
- On both hooks, templates read controller/shared data with `view_data()` — see [View management](#12-view-management).

---



## 10. Route-level handlers

When **no controller action** is set, route-level `redirect()` / `template()` run. If an action is set, the controller return wins.

```php
Route::site(function () {
    // Redirect-only route
    Route::get('old-shop', null)
        ->redirect('/shop', 301)
        ->name('legacy.shop');

    // Template-only route (prefer returning view() from a controller when you need view_data())
    Route::get('promo', null)
        ->template('promotions/summer')
        ->with(['campaign' => 'summer-2026'])
        ->template_redirect()
        ->name('promo.summer');
});
```

**Notes for** `->template()` **without a controller:**

- On `template_redirect`, the framework includes the located file after merging `->with()` data into route params and `extract()`ing them as local variables (legacy path — not `view_data()`).
- On `template_include`, the framework returns the located filesystem path only; it does **not** prepare `ViewContext`, so `view_data()` is unavailable unless you return a `view()` from a controller instead.
- Prefer `return view('promotions.summer', ['campaign' => 'summer-2026'])` when you want the modern view stack.

Controller takes precedence:

```php
Route::get('promo', [PromoController::class, 'show'])
    ->as_site()
    ->template('promotions/summer') // ignored when action is set
    ->name('promo.summer');
```

```php
public function show(Request $request)
{
    return view('promotions.winter', ['campaign' => 'winter-2026']); // this wins
}
```

---



## 11. Controller returns

Site route controllers (and closures) may return:


| Return type            | `template_include` (default)                                           | `template_redirect`                                             |
| ---------------------- | ---------------------------------------------------------------------- | --------------------------------------------------------------- |
| `View` (layout ON)     | Layout wrapper path; header + content + footer; data via `view_data()` | Render via `TemplateEngine` with layout; data via `view_data()` |
| `View` (`->partial()`) | Raw resolved path; no framework header/footer                          | Render without layout                                           |
| `RedirectResponse`     | Sent immediately when returned                                         | `wp_safe_redirect()` + exit                                     |
| `JsonResponse`         | Prefer `->template_redirect()`                                         | Echo JSON (no theme layout)                                     |
| `string` (file path)   | Returned as the template path when the file exists                     | Echo as body text                                               |
| `string` (HTML)        | Prefer `->template_redirect()`                                         | Echo as body text (must be pre-escaped / safe)                  |
| `null`                 | No output (useful with route-level handlers)                           | No output                                                       |


```php
use function Framework\view;
use function Framework\redirect;
use function Framework\response;

class ProductController
{
    public function show(ProductShowRequest $request, Product $product)
    {
        return view('shop.product', ['product' => $product]);
    }

    public function checkout(CheckoutRequest $request)
    {
        return redirect(Route::site_url('orders.thankyou'));
    }

    public function api_stock(Request $request, Product $product)
    {
        return response()->json(['stock' => $product->stock]);
    }

    public function raw(Request $request)
    {
        // Plain string returns are not auto-escaped — emit only safe HTML
        return '<p>' . esc_html__('Plain HTML escape hatch', 'textdomain') . '</p>';
    }
}
```

**Expected:** On `template_include`, templates read controller data with `view_data()`. JSON/raw string responses that need to bypass the theme should use `->template_redirect()`.

---



## 12. View management

How site templates receive data, nest partials, wrap with theme layout, and escape output for WordPress.org review.

### view_data()

The framework does **not** `extract()` view data into local variables. Templates on both dispatch hooks read controller and shared data through `view_data()` only.

```php
// Controller
return view('shop.product', ['product' => $product]);

// resources/views/shop/product.php
$product = view_data('product');
$name = view_data('product.name');
?>
<h1><?php echo esc_html((string) $name); ?></h1>
```

`view_data()` supports dot notation for nested keys. It only returns values when called from the matched template, a nested `include_view()` partial, or a file in that include chain. Unrelated templates cannot read another route's data.

Share values with every view/partial via the template engine:

```php
use Framework\View\TemplateEngine;

use function Framework\app;

app(TemplateEngine::class)->share('site_name', get_bloginfo('name'));
```



### include_view() nested partials

`include_view($view, $data)` is the Laravel `@include` equivalent for pure-PHP templates. It pushes an isolated `ViewContext` frame (explicit `$data` plus shared engine data), requires the partial, then pops the frame.

```php
use function Framework\include_view;
use function Framework\view_data;

// resources/views/shop/products.php
$products = view_data('products');
include_view('shop.filter', ['categories' => view_data('categories')]);
foreach ($products as $product) :
    include_view('shop.product-card', ['product' => $product]);
endforeach;
```

```php
// resources/views/shop/filter.php
<?php foreach (view_data('categories') as $category) : ?>
    <li><?php echo esc_html((string) $category); ?></li>
<?php endforeach; ?>
```

Partials receive only the explicit `$data` argument plus TemplateEngine shared data. Parent keys are not inherited unless passed. Missing views throw `RuntimeException`.

### Layout and partials

Layout (theme header/footer) is **ON by default** on both hooks. Use `View::partial()` to skip it.

On `template_include`, a layout-enabled `View` causes WordPress to load a framework layout wrapper; the wrapper renders the view and wraps it with theme header/footer. `View::partial()` returns the raw view path instead.

`Route::partial()` / `Route::layout()` apply only on `template_redirect`. On `template_include`, only the returned view's layout flag controls wrapping.

```php
// Full page with theme header/footer (both hooks)
return view('shop.product', ['product' => $product]);

// Fragment / AJAX partial — no layout (both hooks via View::partial)
return view('shop.product-card', ['product' => $product])->partial();

// Route-level: disable layout for views from this route (template_redirect only)
Route::get('widget', [WidgetController::class, 'render'])
    ->as_site()
    ->template_redirect()
    ->partial()
    ->name('widget');
```

```php
// Re-enable layout if a route was marked partial (template_redirect)
Route::get('page', [PageController::class, 'show'])
    ->as_site()
    ->template_redirect()
    ->layout(true);
```

**Expected:** Classic themes call `get_header()` / `get_footer()`. Block themes render template-part blocks inside a minimal HTML shell. Layout assembly returns HTML strings; dynamic data is escaped in templates (below).

### Escaping view output ([WordPress.org](http://WordPress.org))

`view_data()` returns **raw** values. Escape at the point of echo with the function that matches the HTML context:

```php
<?php
$name = view_data('product.name');
$url = view_data('product.url');
$description = view_data('product.description');
?>
<h1><?php echo esc_html((string) $name); ?></h1>
<a href="<?php echo esc_url((string) $url); ?>">
    <img src="<?php echo esc_url((string) view_data('product.image')); ?>"
         alt="<?php echo esc_attr((string) $name); ?>">
</a>
<div class="description">
    <?php echo wp_kses_post((string) $description); ?>
</div>
```


| Context                     | Function         |
| --------------------------- | ---------------- |
| HTML body text              | `esc_html()`     |
| HTML attribute              | `esc_attr()`     |
| URL (`href`, `src`)         | `esc_url()`      |
| Trusted rich HTML (WYSIWYG) | `wp_kses_post()` |


Do **not** use `esc_html()` on fields that must retain markup — use `wp_kses_post()` (or a narrower allowlist) instead.

**Controller string returns:** If a `template_redirect` controller returns a plain string (not a `view()`), that string must already be safe HTML. The framework does not auto-escape it. Prefer returning `view(...)` when possible.

---



## 13. Middleware, authorize, validate

Site routes use the **same** pipeline as REST:

1. Build `Request` (`Request::capture()` + sanitized route params)
2. Authorize (Form Request `authorize()`)
3. Middleware pipeline (class-based `Middleware`)
4. Validate (Form Request rules)
5. Resolve controller dependencies and invoke

```php
use Framework\Contracts\Middleware;
use Framework\Contracts\Request as RequestContract;

class AuthMiddleware implements Middleware
{
    public function handle(RequestContract $request, callable $next)
    {
        if (!is_user_logged_in()) {
            return redirect(wp_login_url($request->path()));
        }

        return $next($request);
    }
}

Route::site(function () {
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware([AuthMiddleware::class])
        ->name('checkout.store');
});
```

Form Request example:

```php
class CheckoutRequest extends FormRequest
{
    public function authorize()
    {
        return is_user_logged_in();
    }

    public function rules()
    {
        return [
            'payment_method' => 'required|string',
        ];
    }
}
```

**Expected:** Failed authorize → 403. Failed validation → 422. Middleware can short-circuit with a redirect or response.

> CSRF is not built-in for v1. Verify nonces in a Form Request or custom middleware with `wp_verify_nonce()`.

---



## 14. Closure routes

Closures receive the same dependency injection as controllers — Request, route params, models, and container services — on both site and REST routes:

```php
use Framework\Http\Request;

use function Framework\view;

Route::site(function () {
    Route::get('hello/{name:alpha}', function (Request $request, string $name) {
        return view('hello', ['name' => $name]);
    })->name('hello');

    Route::get('ping', function (Request $request) {
        return response()->json(['ok' => true]);
    })->middleware([AuthMiddleware::class]);
});

// REST closures get the same injection
Route::get('/hello/{name:alpha}', function (Request $request, string $name) {
    return response()->json(['hello' => $name]);
});
```

**Expected:** Typed closure arguments are resolved via reflection (route params → builtins, models via binding, services from the container). Exactly one Request/FormRequest-typed parameter is required (same rule as controllers).

---



## 15. Error handling

Unhandled exceptions on site routes go through `SiteExceptionHandler`:


| Exception                | HTTP status                                  |
| ------------------------ | -------------------------------------------- |
| `AuthorizationException` | 403                                          |
| `ModelNotFoundException` | 404                                          |
| `ValidationException`    | 422                                          |
| Other / unhandled        | 500 (or exception code if valid HTTP status) |


```php
public function show(Request $request, Product $product)
{
    // Model binding uses first_or_fail() → ModelNotFoundException → 404
    return view('shop.product', ['product' => $product]);
}
```

**Expected:** WordPress shows a `wp_die()` error page with the mapped status. REST routes continue to use `ApiExceptionHandler` / `WP_Error` responses.

For an "other / unhandled" exception that resolves to a 5xx status, both handlers replace the message with a generic `Internal Server Error` unless `WP_DEBUG` is on, because such messages can carry SQL, bindings, or file paths. The original message is always written to the log. A 4xx code keeps its message (`new Exception('Not Found', 404)` still shows "Not Found"), and an `HttpException` always shows the message it was given, 5xx included, since that text was chosen for the client.

---



## 16. Template paths

View resolution order:

1. Theme override: `{child_theme}/views/{path}.php` via `locate_template('views/...')`
2. Application path: `{view_path}/{path}.php` (default `{base_path}/resources/views/`)
3. Filter: `framework/view/path`

```php
// Dot notation → resources/views/shop/product.php
return view('shop.product', ['product' => $product]);
```

Theme override example:

```
wp-content/themes/your-theme/views/shop/product.php
```

How templates read data, nest partials, wrap layout, and escape output is covered in [View management](#12-view-management).

---



## Quick reference

```php
// REST
Route::get('/events/{id:int}', [EventsController::class, 'show']);

// Site (block)
Route::site(function () {
    Route::group(['prefix' => 'shop'], function () {
        Route::get('products/{id:int}', [ProductController::class, 'show'])
            ->name('products.show');
    });
});

// Site (fluent)
Route::get('dashboard', [DashboardController::class, 'index'])
    ->as_site()
    ->name('dashboard');

// URL + active check
Route::site_url('products.show', ['id' => 5]);
Route::is('products.show');
Route::route_param('id');

// Views
view('shop.product', ['product' => $product]);
view_data('product.name');
include_view('shop.filter', ['categories' => $categories]);
```

