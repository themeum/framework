<?php

$framework_dir = dirname(__DIR__, 2);

if (!defined('ABSPATH')) {
    define('ABSPATH', $framework_dir . '/');
}

if (!defined('COOKIEPATH')) {
    define('COOKIEPATH', '/wp-subdir/');
}

if (!defined('COOKIE_DOMAIN')) {
    define('COOKIE_DOMAIN', 'example.test');
}

$autoload = $framework_dir . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Run composer install before running tests.\n");
    exit(1);
}

require_once $autoload;

require_once dirname(__DIR__) . '/Support/StubsWordPressFunctions.php';

require_once dirname(__DIR__) . '/Support/StubsWordPressHttp.php';

require_once $framework_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

define('FRAMEWORK_UNIT_TESTS', true);
