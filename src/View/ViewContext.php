<?php
/**
 * Active view data context stack for site route templates.
 *
 * Registers controller and nested partial view data and exposes it via
 * view_data(), with caller verification to prevent hijacking.
 *
 * @package    Framework
 * @subpackage View
 * @since      2.1.2
 */
namespace Framework\View;

defined('ABSPATH') || exit;

use Framework\Supports\Arr;

use function Framework\app;

class ViewContext
{
    /**
     * Stack of view contexts for the current request (root + nested partials).
     *
     * @var array
     *
     * @since 2.1.2
     */
    protected $stack = [];

    /**
     * Whether the shutdown clear hook was registered.
     *
     * @var bool
     *
     * @since 2.1.2
     */
    protected $shutdown_registered = false;

    /**
     * Prepare and push view data for a site route view.
     *
     * @param View $view Controller view return value.
     * @param string $route_name Named route or empty string.
     * @param string $resolved_path Absolute template filesystem path.
     *
     * @return array Final data stored in the pushed context.
     *
     * @since 2.1.2
     */
    public function prepare(
        View $view,
        string $route_name,
        string $resolved_path
    ) {
        $engine = app(TemplateEngine::class);
        $template = $view->get_template();
        $data = array_merge($engine->get_shared(), $view->get_data());

        $this->push([
            'template' => $template,
            'route_name' => $route_name,
            'resolved_path' => $this->normalize_path($resolved_path),
            'data' => $data,
        ]);

        return $data;
    }

    /**
     * Push a context frame onto the stack.
     *
     * @param array $context Context payload with template, route_name, resolved_path, data.
     *
     * @return void
     *
     * @since 2.1.2
     */
    public function push(array $context)
    {
        if (isset($context['resolved_path'])) {
            $context['resolved_path'] = $this->normalize_path((string) $context['resolved_path']);
        }

        $this->stack[] = $context;
        $this->ensure_shutdown_registered();
    }

    /**
     * Pop the top context frame from the stack.
     *
     * @return array|null The removed frame, or null when the stack is empty.
     *
     * @since 2.1.2
     */
    public function pop()
    {
        if ($this->stack === []) {
            return null;
        }

        return array_pop($this->stack);
    }

    /**
     * Read a value from the innermost authorized context.
     *
     * Supports dot notation for nested keys (e.g. `product.name`).
     *
     * @param string|null $key Data key, or null for the full array.
     * @param mixed $default Default when missing or unauthorized.
     *
     * @return mixed
     *
     * @since 2.1.2
     */
    public function get($key = null, $default = null)
    {
        $frame = $this->authorized_frame();

        if ($frame === null) {
            return $key === null ? [] : $default;
        }

        if ($key === null) {
            return $frame['data'];
        }

        return Arr::get($frame['data'], $key, $default);
    }

    /**
     * Clear the entire context stack.
     *
     * @return void
     *
     * @since 2.1.2
     */
    public function clear()
    {
        $this->stack = [];
    }

    /**
     * Get the top context frame, or null when the stack is empty.
     *
     * @return array|null
     *
     * @since 2.1.2
     */
    public function get_active()
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[count($this->stack) - 1];
    }

    /**
     * Set an attribute on the topmost context frame.
     *
     * @param string $key   Attribute key.
     * @param mixed  $value Attribute value.
     *
     * @return void
     *
     * @since 2.2.0
     */
    public function set_active_attribute(string $key, $value)
    {
        if ($this->stack === []) {
            return;
        }

        $this->stack[count($this->stack) - 1][$key] = $value;
    }

    /**
     * Find the innermost stack frame that authorizes the current caller.
     *
     * @return array|null
     *
     * @since 2.1.2
     */
    protected function authorized_frame()
    {
        if ($this->stack === []) {
            return null;
        }

        $trace_files = $this->trace_files();

        for ($index = count($this->stack) - 1; $index >= 0; $index--) {
            $frame = $this->stack[$index];

            if (empty($frame['resolved_path'])) {
                continue;
            }

            if (isset($trace_files[$frame['resolved_path']])) {
                return $frame;
            }
        }

        return null;
    }

    /**
     * Collect normalized file paths from the current backtrace.
     *
     * @return array<string, bool> Map of normalized path => true.
     *
     * @since 2.1.2
     */
    protected function trace_files()
    {
        $files = [];
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($trace as $frame) {
            if (empty($frame['file'])) {
                continue;
            }

            $files[$this->normalize_path($frame['file'])] = true;
        }

        return $files;
    }

    /**
     * Register a shutdown callback to clear the stack once.
     *
     * @return void
     *
     * @since 2.1.2
     */
    protected function ensure_shutdown_registered()
    {
        if ($this->shutdown_registered) {
            return;
        }

        $this->shutdown_registered = true;

        add_action('shutdown', function () {
            $this->clear();
        }, 999);
    }

    /**
     * Normalize a filesystem path for comparison.
     *
     * @param string $path Absolute path.
     *
     * @return string
     *
     * @since 2.1.2
     */
    protected function normalize_path(string $path)
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        return str_replace('\\', '/', $path);
    }
}
