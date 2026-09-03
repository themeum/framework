<?php
/**
 * Template engine for rendering site route views with theme layout support.
 * Resolves views from the theme first, then the application view path.
 *
 * @package    Framework
 * @subpackage View
 * @since      1.0.0
 */
namespace Framework\View;

defined('ABSPATH') || exit;

use RuntimeException;

use function Framework\app;

class TemplateEngine
{
    /**
     * Shared data available to all views.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $shared = [];

    /**
     * Cached block theme header markup.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $block_header = '';

    /**
     * Cached block theme footer markup.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $block_footer = '';

    /**
     * Share a value with all views.
     *
     * @param string $key The data key.
     * @param mixed $value The data value.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function share(string $key, $value)
    {
        $this->shared[$key] = $value;
    }

    /**
     * Get data shared with all views.
     *
     * @return array
     *
     * @since 2.1.2
     */
    public function get_shared()
    {
        return $this->shared;
    }

    /**
     * Render a view template to a string.
     *
     * @param string      $view   The view name in dot notation.
     * @param array       $data   The data to pass to the view.
     * @param bool|string $layout Layout mode: true for theme wrapping,
     *                            false for no wrapping, or a master
     *                            layout template name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function render(string $view, array $data = [], $layout = true)
    {
        $path = $this->resolve_path($view);

        if ($path === '') {
            throw new RuntimeException(sprintf('View [%s] not found.', $view));
        }

        $merged = array_merge($this->shared, $data);
        $context = app(ViewContext::class);
        $context->push([
            'template' => $view,
            'route_name' => '',
            'resolved_path' => $path,
            'data' => $merged,
        ]);

        try {
            if (is_string($layout)) {
                return $this->render_with_master_layout($path, $layout);
            }

            $content = $this->render_file($path);

            if (!$layout) {
                return $content;
            }

            return $this->wrap_layout($content);
        } finally {
            $context->pop();
        }
    }

    /**
     * Render a child template within a master layout.
     *
     * The child template is executed first, populating sections via
     * SectionManager. Then the master layout is rendered, yielding
     * those sections with render_section().
     *
     * @param string $child_path  Absolute path to the child template.
     * @param string $master_view Master layout template name in dot notation.
     *
     * @return string
     *
     * @throws RuntimeException When the master layout cannot be resolved.
     *
     * @since 2.2.0
     */
    protected function render_with_master_layout(string $child_path, string $master_view)
    {
        $master_path = $this->resolve_path($master_view);

        if ($master_path === '') {
            throw new RuntimeException(sprintf('Master layout [%s] not found.', $master_view));
        }

        $sections = app(SectionManager::class);
        $sections->clear();

        // Render the child template – its start_section() / end_section()
        // calls populate the SectionManager.
        $this->render_file($child_path);

        // Render the master layout, which calls render_section() to yield
        // the captured sections.
        $output = $this->render_file($master_path);

        $sections->clear();

        return $output;
    }

    /**
     * Resolve a view name to an absolute file path.
     *
     * @param string $view The view name in dot notation.
     *
     * @return string Absolute path or empty string when not found.
     *
     * @since 1.0.0
     */
    public function resolve_path(string $view)
    {
        $relative = $this->normalize_template_name($view) . '.php';

        if (function_exists('locate_template')) {
            $theme_path = locate_template('views/' . $relative);

            if ($theme_path !== '') {
                return apply_filters('framework/view/path', $theme_path, $view);
            }
        }

        $base_path = app()->view_path();
        $candidate = rtrim($base_path, '/\\') . '/' . $relative;
        $resolved = realpath($candidate);
        $base_real = realpath($base_path);

        if ($resolved === false || $base_real === false || strpos($resolved, $base_real) !== 0) {
            return apply_filters('framework/view/path', '', $view);
        }

        if (!is_file($resolved) || !is_readable($resolved)) {
            return apply_filters('framework/view/path', '', $view);
        }

        return apply_filters('framework/view/path', $resolved, $view);
    }

    /**
     * Normalize a template name into a relative path without extension.
     *
     * @param string $template Template name or relative path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function normalize_template_name(string $template)
    {
        $template = preg_replace('/\.php$/i', '', trim($template));

        return str_replace('.', '/', $template);
    }

    /**
     * Check if the current theme is a block theme.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_block_theme()
    {
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    /**
     * Render a PHP template file without extracting data into local variables.
     *
     * Templates must read data via view_data().
     *
     * @param string $path Absolute template path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function render_file(string $path)
    {
        ob_start();

        // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
        (static function ($__path) {
            // phpcs:ignore Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase
            require $__path;
        })($path);

        return (string) ob_get_clean();
    }

    /**
     * Wrap rendered content with theme header and footer.
     *
     * Assembles HTML as a string. Dynamic view data must be escaped in templates
     * before it becomes part of $content.
     *
     * @param string $content The view content.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function wrap_layout(string $content)
    {
        return $this->render_header() . $content . $this->render_footer();
    }

    /**
     * Render the theme header for classic or block themes.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function render_header()
    {
        if ($this->is_block_theme()) {
            $this->block_header = do_blocks(
                sprintf(
                    '<!-- wp:template-part {"slug":"header","theme":"%s","tagName":"header","layout":{"inherit":true}} /-->',
                    esc_attr(get_stylesheet())
                )
            );
            $this->block_footer = do_blocks(
                sprintf(
                    '<!-- wp:template-part {"slug":"footer","theme":"%s","tagName":"footer","className":"site-footer","layout":{"inherit":true}} /-->',
                    esc_attr(get_stylesheet())
                )
            );

            ob_start();
            ?>
            <!doctype html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="<?php bloginfo('charset'); ?>">
                <?php wp_head(); ?>
            </head>
            <body <?php body_class(); ?>>
                <?php wp_body_open(); ?>
                <div class="wp-site-blocks">
            <?php

            return (string) ob_get_clean() . $this->block_header;
        }

        ob_start();
        get_header();

        return (string) ob_get_clean();
    }

    /**
     * Render the theme footer for classic or block themes.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function render_footer()
    {
        if ($this->is_block_theme()) {
            ob_start();
            wp_footer();
            $footer_scripts = (string) ob_get_clean();

            return $this->block_footer . '</div>' . $footer_scripts . '</body></html>';
        }

        ob_start();
        get_footer();

        return (string) ob_get_clean();
    }

    /**
     * Include a view template file directly (without capturing output).
     *
     * @param string $view The view name in dot notation.
     * @param array $data The data to pass to the view.
     * @param bool $once Whether to load the template only once.
     *
     * @return void
     *
     * @throws RuntimeException When the view cannot be resolved.
     *
     * @since 1.0.0
     */
    public function include(string $view, array $data = [], bool $once = true)
    {
        $path = $this->resolve_path($view);

        if ($path === '') {
            throw new RuntimeException(sprintf('View [%s] not found.', $view));
        }

        $merged = array_merge($this->shared, $data);
        $context = app(ViewContext::class);
        $context->push([
            'template' => $view,
            'route_name' => '',
            'resolved_path' => $path,
            'data' => $merged,
        ]);

        try {
            if ($once) {
                require_once $path;

                return;
            }

            require $path;
        } finally {
            $context->pop();
        }
    }

    /**
     * Absolute path to the framework layout wrapper stub.
     *
     * @return string
     *
     * @since 2.1.2
     */
    public function layout_wrapper_path()
    {
        return dirname(__FILE__) . '/layout-wrapper.php';
    }
}
