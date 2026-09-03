<?php
/**
 * Section manager for master layout template composition.
 *
 * Manages named content sections via output buffering, allowing child
 * templates to define sections that master layouts can yield.
 *
 * @package    Framework
 * @subpackage View
 * @since      2.2.0
 */
namespace Framework\View;

defined('ABSPATH') || exit;

use RuntimeException;

class SectionManager
{
    /**
     * Stored section contents keyed by name.
     *
     * @var array<string, string>
     *
     * @since 2.2.0
     */
    protected $sections = [];

    /**
     * Name of the section currently being captured, or null.
     *
     * @var string|null
     *
     * @since 2.2.0
     */
    protected $active_section = null;

    /**
     * Begin capturing a named section.
     *
     * @param string $name Section name.
     *
     * @return void
     *
     * @throws RuntimeException When a section is already being captured.
     *
     * @since 2.2.0
     */
    public function start(string $name)
    {
        if ($this->active_section !== null) {
            throw new RuntimeException(
                sprintf(
                    'Cannot start section [%s] while section [%s] is already being captured.',
                    $name,
                    $this->active_section
                )
            );
        }

        $this->active_section = $name;

        ob_start();
    }

    /**
     * End the current section capture and store the buffered content.
     *
     * @return void
     *
     * @throws RuntimeException When no section is being captured.
     *
     * @since 2.2.0
     */
    public function end()
    {
        if ($this->active_section === null) {
            throw new RuntimeException('Cannot end section: no section is being captured.');
        }

        $this->sections[$this->active_section] = (string) ob_get_clean();
        $this->active_section = null;
    }

    /**
     * Get a section's content, or the default value if not defined.
     *
     * @param string $name    Section name.
     * @param string $default Fallback content when the section was not defined.
     *
     * @return string
     *
     * @since 2.2.0
     */
    public function get(string $name, string $default = '')
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Check if a section has been defined.
     *
     * @param string $name Section name.
     *
     * @return bool
     *
     * @since 2.2.0
     */
    public function has(string $name)
    {
        return isset($this->sections[$name]);
    }

    /**
     * Clear all stored sections and reset active capture state.
     *
     * @return void
     *
     * @since 2.2.0
     */
    public function clear()
    {
        $this->sections = [];
        $this->active_section = null;
    }

    /**
     * Get the name of the currently active section, or null.
     *
     * @return string|null
     *
     * @since 2.2.0
     */
    public function get_active()
    {
        return $this->active_section;
    }
}
