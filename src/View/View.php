<?php
/**
 * Renderable view value object returned by the view() helper.
 *
 * @package    Framework
 * @subpackage View
 * @since      1.0.0
 */
namespace Framework\View;

defined('ABSPATH') || exit;

use function Framework\app;

class View
{
    /**
     * The template name in dot notation.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $template;

    /**
     * Data passed to the template.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $data = [];

    /**
     * Layout wrapping mode for this view.
     *
     * - true:   Wrap with the standard theme header/footer.
     * - false:  No theme wrapping (partial).
     * - string: Wrap with the specified custom master layout template.
     *
     * @var bool|string
     *
     * @since 1.0.0
     */
    protected $with_layout = true;

    /**
     * Create a new View instance.
     *
     * @param string $template The template name.
     * @param array $data The template data.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $template, array $data = [])
    {
        $this->template = $template;
        $this->data = $data;
    }

    /**
     * Disable layout wrapping for this view.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function partial()
    {
        $this->with_layout = false;

        return $this;
    }

    /**
     * Enable or set layout wrapping for this view.
     *
     * Pass `true` for standard theme wrapping, `false` to disable,
     * or a template name string (e.g. 'site.account.master') for
     * a custom master layout.
     *
     * @param bool|string $layout Layout mode or master template name.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function layout($layout = true)
    {
        $this->with_layout = is_string($layout) ? $layout : (bool) $layout;

        return $this;
    }

    /**
     * Merge additional data into the view.
     *
     * @param array $data The data to merge.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function with(array $data)
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /**
     * Get the template name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_template()
    {
        return $this->template;
    }

    /**
     * Get the view data.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_data()
    {
        return $this->data;
    }

    /**
     * Whether the view uses layout wrapping.
     *
     * Returns true for both standard theme layout (true) and
     * custom master layout (string). Returns false only when
     * layout is explicitly disabled.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function uses_layout()
    {
        return $this->with_layout !== false;
    }

    /**
     * Get the master layout template name, if set.
     *
     * @return string|null Template name in dot notation, or null when
     *                     using standard theme layout or no layout.
     *
     * @since 2.2.0
     */
    public function get_master_layout()
    {
        return is_string($this->with_layout) ? $this->with_layout : null;
    }

    /**
     * Render the view to a string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function render()
    {
        return app(TemplateEngine::class)->render(
            $this->template,
            $this->data,
            $this->with_layout
        );
    }

    /**
     * Render the view when cast to string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function __toString()
    {
        return $this->render();
    }
}
