<?php
/**
 * SplFileInfo subclass representing a single filesystem file with move and put helpers.
 * Validates existence on construction when path checking is enabled.
 * Wraps PHP file operations with framework-level error handling.
 *
 * @package    Framework
 * @subpackage Filesystem
 * @since      1.0.0
 */
namespace Framework\Filesystem;

defined('ABSPATH') || exit;

use Exception;
use Framework\Container;
use InvalidArgumentException;
use SplFileInfo;

use function Framework\throw_anyway;
use function Framework\throw_if;
use function Framework\throw_unless;

class File extends SplFileInfo
{
    /**
     * Create a new file instance.
     *
     * @param string $path The path.
     * @param bool $check_path The check path.
     *
     * @return void
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    public function __construct(string $path, bool $check_path = true)
    {
        throw_if(
            $check_path && !file_exists($path),
            "File does not exist at path: {$path}",
            InvalidArgumentException::class
        );

        parent::__construct($path);
    }

    /**
     * Move the file to a new location.
     *
     * @param string $directory The directory.
     * @param ?string $name The name.
     *
     * @return static
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function move(string $directory, ?string $name = null)
    {
        $target = $this->get_target_file($directory, $name);

        $filesystem = Container::get_instance()->make(Filesystem::class);

        $renamed = $filesystem->move($this->getPathname(), $target);

        throw_unless(
            $renamed,
            sprintf('Could not move the file "%s" to "%s".', $this->getPathname(), $target),
            Exception::class
        );

        $filesystem->chmod($target, 0666 & ~umask());

        return $target;
    }

    /**
     * Get the contents of the file.
     *
     * @return string
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function get_content()
    {
        $content = file_get_contents($this->getPathname());

        throw_if($content === false, sprintf('Unable to read the file "%s".', $this->getPathname()));

        return $content;
    }

    /**
     * Get the target file.
     *
     * @param string $directory The directory.
     * @param ?string $name The name.
     *
     * @return static
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function get_target_file(string $directory, ?string $name = null)
    {
        if (!is_dir($directory) && !wp_mkdir_p($directory) && !is_dir($directory)) {
            throw_if(
                is_file($directory),
                sprintf('Unable to create the "%s" directory. A similar named file exists.', $directory),
                Exception::class
            );

            throw_anyway(sprintf('Unable to create the "%s" directory.', $directory));
        } elseif (!wp_is_writable($directory)) {
            throw_anyway(sprintf('Unable to write in the "%s" directory.', $directory));
        }

        $target = rtrim($directory, '/\\')
            . \DIRECTORY_SEPARATOR
            . ($name === null ? $this->getBasename() : $this->get_name($name));

        return new self($target, false);
    }

    /**
     * Get the name of the file.
     *
     * @param string $name The name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_name(string $name)
    {
        $original_name = str_replace('\\', '/', $name);
        $position = strrpos($original_name, '/');

        return $position === false ? $original_name : substr($original_name, $position + 1);
    }
}
