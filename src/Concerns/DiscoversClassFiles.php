<?php
/**
 * Trait that walks a PSR-4 directory tree and returns each PHP file's class path relative to it.
 * Discovery must see classes grouped into subfolders, which a flat glob() of the top level misses.
 * Used by the listener and policy discovery to turn nested files into namespaced class names.
 *
 * @package    Framework
 * @subpackage Concerns
 * @since      1.0.0
 */
namespace Framework\Concerns;

defined('ABSPATH') || exit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait DiscoversClassFiles
{
    /**
     * Get the class path of every PHP file beneath the directory, relative to it.
     *
     * A file at `Order/SendInvoice.php` yields `Order\SendInvoice`, ready to be appended to the
     * directory's base namespace. The result is sorted so the generated cache files stay stable
     * regardless of the order the filesystem returns entries in.
     *
     * @param string $directory The directory to walk.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function class_files(string $directory)
    {
        $class_files = [];

        if (!is_dir($directory)) {
            return $class_files;
        }

        $prefix_length = strlen(rtrim($directory, '\/')) + 1;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative_path = substr($file->getPathname(), $prefix_length, -strlen('.php'));
            $class_files[] = str_replace('/', '\\', $relative_path);
        }

        sort($class_files);

        return $class_files;
    }
}
