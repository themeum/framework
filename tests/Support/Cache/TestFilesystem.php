<?php

namespace Framework\Tests\Support\Cache;

use Framework\Exceptions\NotFoundException;
use Framework\Filesystem\Filesystem;

/**
 * A Filesystem that talks to the real disk instead of WP_Filesystem.
 *
 * The file store is exercised against actual files so that the execution guard, the directory
 * fan-out, the protection files and the write-then-move are all verified for real rather than
 * against a fake that could agree with a wrong implementation.
 */
class TestFilesystem extends Filesystem
{
    public function __construct()
    {
        // Deliberately does not call WP_Filesystem().
    }

    public function is_file($file)
    {
        return is_file($file);
    }

    public function exists($path)
    {
        return file_exists($path);
    }

    public function missing($path)
    {
        return !$this->exists($path);
    }

    public function is_directory($path)
    {
        return is_dir($path);
    }

    public function dirname($path)
    {
        return dirname($path);
    }

    public function glob($pattern, $flags = 0)
    {
        return glob($pattern, $flags);
    }

    public function scan_directory($directory, int $limit = 0, $extension = null)
    {
        $paths = [];

        if (!is_dir($directory)) {
            return $paths;
        }

        foreach (new \DirectoryIterator($directory) as $item) {
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            if (!is_null($extension) && $item->getExtension() !== $extension) {
                continue;
            }

            $paths[] = $item->getPathname();

            if ($limit > 0 && count($paths) >= $limit) {
                break;
            }
        }

        return $paths;
    }

    public function make_dir($path)
    {
        $path = pathinfo($path, PATHINFO_EXTENSION) !== '' ? $this->dirname($path) : $path;

        return is_dir($path) ? true : mkdir($path, 0777, true);
    }

    public function put($path, $data)
    {
        $this->make_dir($this->dirname($path));

        return file_put_contents($path, $data) !== false;
    }

    public function get($path)
    {
        if (!is_file($path)) {
            throw new NotFoundException('File not found: ' . $path);
        }

        return file_get_contents($path);
    }

    public function move($path, $target)
    {
        return rename($path, $target);
    }

    public function delete($paths, bool $recursive = true)
    {
        $paths = is_array($paths) ? $paths : [$paths];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                if (!$recursive) {
                    continue;
                }

                foreach (glob(rtrim($path, '/') . '/{,.}*', GLOB_BRACE) ?: [] as $child) {
                    if (in_array(basename($child), ['.', '..'], true)) {
                        continue;
                    }

                    $this->delete($child, true);
                }

                @rmdir($path);

                continue;
            }

            if (is_file($path)) {
                unlink($path);
            }
        }

        return true;
    }

    public function last_modified($path)
    {
        return filemtime($path);
    }
}
