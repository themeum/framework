<?php
/**
 * Layout wrapper for template_include views that use theme header/footer
 * or a custom master layout.
 *
 * WordPress includes this file via template_include. The active ViewContext
 * holds the real view path and data; this stub renders content and wraps it.
 *
 * @package    Framework
 * @subpackage View
 * @since      2.1.2
 */

defined('ABSPATH') || exit;

use Framework\View\SectionManager;
use Framework\View\TemplateEngine;
use Framework\View\ViewContext;

use function Framework\app;

$context = app(ViewContext::class);
$active = $context->get_active();

if ($active === null || empty($active['resolved_path'])) {
    return;
}

$path = $active['resolved_path'];
$engine = app(TemplateEngine::class);

// Master layout: child populates sections, then master layout renders around them.
if (!empty($active['master_layout'])) {
    $master_path = $engine->resolve_path($active['master_layout']);

    if ($master_path === '') {
        return;
    }

    $sections = app(SectionManager::class);
    $sections->clear();

    // Execute the child template to populate sections.
    ob_start();
    require $path;
    ob_end_clean();

    // Render the master layout which yields the captured sections.
    ob_start();
    require $master_path;
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled layout HTML; dynamic data is escaped in view templates via esc_*.
    echo (string) ob_get_clean();

    $sections->clear();

    return;
}

// Standard theme layout: wrap with header/footer.
ob_start();
require $path;
$content = (string) ob_get_clean();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled layout HTML; dynamic data is escaped in view templates via esc_*.
echo $engine->wrap_layout($content);
