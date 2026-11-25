# Themeum Framework

An elegant PHP framework for WordPress plugins. This guide walks through integrating `themeum/framework` into a plugin named **Kirki**.

Contributors working on the library itself can use the local playground in [example/README.md](example/README.md).

## Releasing

Maintainers can cut a release with a single command that bumps [`composer.json`](composer.json), commits, tags, and pushes.

**Prerequisites:** check out `main`, ensure the working tree is clean, and fix any failing unit tests before releasing.

```bash
composer release -- -v 1.0.6 -m "Fix validation handling"
```

The `--` separator is required so Composer forwards `-v` and `-m` to the release script instead of treating them as Composer flags.

If `-v` or `-m` is omitted, the script prompts for the version and release message interactively:

```bash
composer release --
```

Preview the release steps without making changes:

```bash
composer release -- --dry-run -v 1.0.6 -m "Fix validation handling"
```

The script will:

1. Validate the version (semver `x.y.z`, greater than the current `composer.json` version)
2. Confirm you are on `main` with a clean working tree and no existing tag locally or on `origin`
3. Run `composer test:unit`
4. Update `composer.json`, commit with the release message, create an annotated tag (`v1.0.6`), and push the commit and tag to `origin`

Run `composer release -- --help` for full flag reference.

## Requirements

- PHP 7.0+
- WordPress 5.0+
- [Composer](https://getcomposer.org/)
- [WP-CLI](https://wp-cli.org/) (for CLI commands)

## Installation

### 1. Require the package from GitHub (VCS)

In your plugin’s `composer.json`, add a VCS repository and require `dev-main`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/themeum/framework"
        }
    ],
    "require": {
        "php": ">=7.0",
        "themeum/framework": "dev-main"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

From the plugin root:

```bash
composer install
```

The unscoped package lands in `vendor/themeum/framework`. That copy is **input for PHP-Scoper only** — do not autoload it in production.

### 2. Install PHP-Scoper

```bash
composer require --dev humbug/php-scoper:^0.18.18
```

Add a `scope` script to `composer.json`:

```json
"scripts": {
    "scope": [
        "@php vendor/bin/php-scoper add-prefix --config=scoper.config.php --force"
    ]
}
```

Create `scoper.config.php` at the plugin root:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

return [
    'prefix' => 'Kirki',
    'output-dir' => 'libraries/kirki/framework',
    'finders' => [
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/themeum/framework/src')
            ->exclude(['test', 'tests', 'Tests'])
            ->name(['*.php', '*.stub']),
    ],
    'exclude-files' => [],
    'exclude-namespaces' => [
        '~^$~',
        '/^(?!Framework($|\\\\))/',
    ],
    'expose-global-classes' => true,
    'expose-global-functions' => true,
    'expose-global-constants' => true,
];
```

PHP-Scoper rewrites `Framework\` to `Kirki\Framework\` and writes the result to `libraries/kirki/framework/`. The `exclude-namespaces` regex keeps every namespace except `Framework\` unprefixed (Composer vendor code stays on its original PSR-4 paths).

WP-CLI generator stubs live under `src/Console/stubs/` as `*.stub` files. Include them in the finder (`->name(['*.php', '*.stub'])`) so they are copied into the scoped tree. Stub templates use placeholders such as `{{namespace}}`, which PHP-Scoper cannot parse; after scoping, run `scripts/prefix-scoped-console-stubs.php` on `libraries/<vendor>/framework/Console/stubs` with your prefix (the example plugin does this via `scripts/post-scope-console-stubs.php` in its `scope` Composer script).

### 3. Autoload the scoped copy only

```json
"autoload": {
    "psr-4": {
        "Kirki\\App\\": "app/",
        "Kirki\\Framework\\": "libraries/kirki/framework/"
    },
    "files": [
        "libraries/kirki/framework/helpers.php"
    ]
}
```

Never register PSR-4 autoload for `vendor/themeum/framework` alongside the scoped tree.

`composer install` in the plugin also installs every package `themeum/framework` requires into the plugin’s `vendor/`. Those stay **unprefixed** at runtime; only the scoped tree under `libraries/` uses your prefix.

### 4. Generate the scoped tree

Before the first run, create a placeholder so Composer can load `helpers.php`:

```bash
mkdir -p libraries/kirki/framework
printf '<?php\n' > libraries/kirki/framework/helpers.php
composer run scope
composer dump-autoload
```

Re-run `composer run scope` after updating `themeum/framework` or when library source changes. Add `libraries/` to `.gitignore` and generate the scoped tree in CI or before release.

Do not activate the plugin until scoping completes — missing `Kirki\Framework\` classes will fatal.

## Plugin structure

After installation, create this layout under the plugin root (empty directories are fine until generators fill them):

```
kirki/
├── kirki.php
├── composer.json
├── scoper.config.php
├── bootstrap/
│   ├── app.php
│   └── providers.php
├── app/                         # Kirki\App\
├── config/
│   └── hooks.php
├── routes/
│   └── api.php
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/                   # optional
├── libraries/kirki/framework/   # generated — do not commit
└── vendor/
```

The application resolves these paths by default: `app/`, `bootstrap/`, `config/`, `database/`, `resources/` relative to the plugin base path. Override with `use_app_path()`, `use_config_path()`, `use_database_path()`, `use_bootstrap_path()`, or `use_resource_path()` on the application instance if needed.

## Usage

### Bootstrap the plugin (`kirki.php`)

```php
<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KIRKI_PATH')) {
    define('KIRKI_PATH', plugin_dir_path(__FILE__));
}

if (!defined('KIRKI_URL')) {
    define('KIRKI_URL', plugin_dir_url(__FILE__));
}

if (!defined('KIRKI_PREFIX')) {
    define('KIRKI_PREFIX', 'kirki');
}

require_once __DIR__ . '/vendor/autoload.php';

add_action('init', 'kirki_boot_application', 0);

function kirki_boot_application()
{
    require_once KIRKI_PATH . 'bootstrap/app.php';
}
```

Configure and boot the application on the `init` hook so WordPress is fully loaded.

### Application (`bootstrap/app.php`)

```php
<?php

use Kirki\Framework\Application;

return Application::configure(KIRKI_PATH)
    ->use_routing(KIRKI_PATH . 'routes/api.php')
    ->use_prefix(KIRKI_PREFIX)
    ->use_app_mode('development')
    ->boot();
```

`use_prefix()` sets the options key prefix (snake_cased). Access it with `app()->prefix()`.

### Service providers (`bootstrap/providers.php`)

Return a list of provider classes:

```php
<?php

use Kirki\App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
```

Place providers under `Kirki\App\Providers\`.

### Hooks (`config/hooks.php`)

Register hook handler classes:

```php
<?php

use Kirki\Framework\Wordpress\Hooks\Actions\SampleActionHook;
use Kirki\Framework\Wordpress\Hooks\Filters\SampleFilterHook;

return [
    'actions' => [
        SampleActionHook::class,
    ],
    'filters' => [
        SampleFilterHook::class,
    ],
];
```

To fire or register a hook directly, use `ActionHook` and `FilterHook`. They pass
their arguments straight to the WordPress core functions. `fire` returns nothing,
and `apply` returns the filtered value:

```php
use Kirki\Framework\Wordpress\ActionHook;
use Kirki\Framework\Wordpress\FilterHook;

ActionHook::add('kirki_order_placed', $callback, 10, 2);  // add_action()
ActionHook::fire('kirki_order_placed', $order, $customer); // do_action()

FilterHook::add('kirki_price', $callback);                 // add_filter()
$price = FilterHook::apply('kirki_price', $price, $product); // apply_filters()
```

### Routes (`routes/api.php`)

```php
<?php

use Kirki\Framework\Http\Request;
use Kirki\Framework\Route;

use function Kirki\Framework\app;
use function Kirki\Framework\response;

Route::set_namespace('kirki/v1');

Route::get('/ping', function (Request $request) {
    return response()->json([
        'status'   => 'ok',
        'dev_mode' => app()->is_dev_mode(),
        'prefix'   => app()->prefix(),
    ]);
});
```

### Other features

- **Migrations** — PHP files in `database/migrations/`. Run with `wp kirki migrate`. See [docs/migrations.md](docs/migrations.md).
- **Validation** — Rules on form requests and validators provided by the framework.
- **Container and facades** — Resolve services via `app()` and framework facades after boot.

## Testing

Test documentation will be added in a future release.

## WP-CLI commands

Commands register when the application boots under WP-CLI. The command namespace is `kirki`:

```bash
wp kirki <command>
```

### `make:migration`

Create a migration file in `database/migrations/`.

```bash
wp kirki make:migration create_users_table
wp kirki make:migration create_orders_table --prefix=wp_
wp kirki make:migration add_slug_to_posts --table=posts
```

The generated class is appended to `config/migrations.php` automatically.

| Argument | Description |
|----------|-------------|
| `name` (positional) | Migration name; should start with `create_` and end with `table` |
| `--prefix` | Optional table prefix |
| `--table` | Existing table to alter; generates an alter migration |
| `--create` | Table to create; generates a create migration |

### `migrate`

Run all pending migrations. Migrations that have already run are skipped.

```bash
wp kirki migrate
```

### `migrate:status`

Show which migrations have run, and the batch each belongs to.

```bash
wp kirki migrate:status
```

### `migrate:rollback`

Undo the migrations of the most recent batch.

```bash
wp kirki migrate:rollback
wp kirki migrate:rollback --step=2
```

| Flag / option | Description |
|---------------|-------------|
| `--step` | Number of batches to roll back (default 1) |

### `migrate:fresh`

Drop all plugin tables, clear migration history, and re-run migrations.

```bash
wp kirki migrate:fresh
wp kirki migrate:fresh --seed
wp kirki migrate:fresh --seed --class=DatabaseSeeder
```

| Flag / option | Description |
|---------------|-------------|
| `--seed` | Run seeders after migrating |
| `--class` | Seeder class name when seeding |

### `db:seed`

Run database seeders from `database/seeders/`.

```bash
wp kirki db:seed
wp kirki db:seed --class=UsersSeeder
wp kirki db:seed --class=UsersSeeder,ProductsSeeder
```

| Option | Description |
|--------|-------------|
| `--class` | One or more seeder classes (comma-separated). Omit to discover all seeders. |

### `make:model`

Create a model class in `app/Models/`.

```bash
wp kirki make:model User
```

### `make:controller`

Create a controller in `app/Http/Controllers/`.

```bash
wp kirki make:controller UserController
```

Supports optional flags for API or resource controllers (see `wp help kirki make:controller`).

### `make:request`

Create a form request class.

```bash
wp kirki make:request StoreUserRequest
```

### `make:provider`

Create a service provider class.

```bash
wp kirki make:provider AppServiceProvider
```

### `make:seeder`

Create a seeder in `database/seeders/`.

```bash
wp kirki make:seeder DatabaseSeeder
```

### `make:class`

Create a generic class under `app/`.

```bash
wp kirki make:class ExampleService
```

Supports an optional folder argument (see `wp help kirki make:class`).

## License

GPL-2.0-or-later
