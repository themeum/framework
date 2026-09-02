<?php
/**
 * WordPress-aware filesystem wrapper around WP_Filesystem with read, write, delete, and directory helpers.
 * Integrates capability checks and sanitization for admin file operations.
 * Supports Macroable extension for custom file helpers.
 *
 * @package    Framework
 * @subpackage Filesystem
 * @since      1.0.0
 */
namespace Framework\Filesystem;

defined('ABSPATH') || exit;

use Exception;
use Framework\Exceptions\AuthorizationException;
use Framework\Exceptions\NotFoundException;
use Framework\Sanitizer;
use Framework\Supports\Traits\Macroable;
use Framework\Wordpress\Constants\Capabilities;
use WP_Filesystem_Base;

use function Framework\Polyfill\str_starts_with;
use function Framework\message;

class Filesystem
{
    use Macroable;

    /**
     * The WordPress filesystem.
     *
     * @var WP_Filesystem_Base
     *
     * @since 1.0.0
     */
    protected $filesystem;

    /**
     * Create a new filesystem instance.
     *
     * @return void
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        global $wp_filesystem;

        $this->filesystem = $wp_filesystem;

        if (!$this->filesystem instanceof WP_Filesystem_Base) {
            throw new \RuntimeException('WordPress filesystem is not available.');
        }
    }

    /**
     * Check if the given path is a file.
     *
     * @param mixed $file The file.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_file($file)
    {
        return $this->filesystem->is_file($file);
    }

    /**
     * Find path names matching a pattern.
     *
     * @param mixed $pattern The pattern.
     * @param mixed $flags The flags.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function glob($pattern, $flags = 0)
    {
        return glob($pattern, $flags);
    }

    /**
     * List a bounded number of files directly inside a directory.
     *
     * Reads directory entries one at a time and stops as soon as the limit is reached, unlike
     * glob(), which must read and sort the entire directory before returning anything. A sweep
     * over a directory that has accumulated many entries stays bounded in memory this way, where
     * glob() would materialise the full listing regardless of how many entries the caller
     * actually needs.
     *
     * @param string $directory The directory to list.
     * @param int $limit The maximum number of files to return. Zero means no limit.
     * @param string|null $extension Only return files with this extension, without the dot.
     *
     * @return array
     *
     * @since 1.0.0
     */
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

    /**
     * Get the base name of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function basename($path)
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * Get the file name of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function name($path)
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Get the file extension of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function extension($path)
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Copy a file to a new location.
     *
     * @param mixed $path The path.
     * @param mixed $target The target.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function copy($path, $target)
    {
        return $this->filesystem->copy($path, $target, true, FS_CHMOD_FILE);
    }

    /**
     * Move a file to a new location.
     *
     * @param mixed $path The path.
     * @param mixed $target The target.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function move($path, $target)
    {
        return $this->filesystem->move($path, $target, true);
    }

    /**
     * Delete the file at a given path.
     *
     * @param mixed $paths The paths.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function delete($paths, bool $recursive = true)
    {
        $paths = is_array($paths) ? $paths : func_get_args();
        $success = true;

        foreach ($paths as $path) {
            if (!$this->filesystem->delete($path, $recursive)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get or set permissions of a file or directory.
     *
     * @param mixed $path The path.
     * @param mixed $mode The mode.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function chmod($path, $mode = null)
    {
        if ($mode) {
            return $this->filesystem->chmod($path, $mode);
        }

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * Append to a file.
     *
     * @param mixed $path The path.
     * @param mixed $data The data payload.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function append($path, $data)
    {
        if ($this->exists($path)) {
            return $this->put($path, $this->get($path) . $data);
        }

        return $this->put($path, $data);
    }

    /**
     * Prepend to a file.
     *
     * @param mixed $path The path.
     * @param mixed $data The data payload.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function prepend($path, $data)
    {
        if ($this->exists($path)) {
            return $this->put($path, $data . $this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * Write the contents of a file.
     *
     * @param mixed $path The path.
     * @param mixed $data The data payload.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function put($path, $data)
    {
        if ($this->missing($path)) {
            $this->make_dir($path);
        }

        return $this->filesystem->put_contents($path, $data, FS_CHMOD_FILE);
    }

    /**
     * Get the contents of a file.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @throws NotFoundException
     *
     * @since 1.0.0
     */
    public function get($path)
    {
        if (!$this->is_file($path)) {
            throw new NotFoundException(message('filesystem.file_not_found', $path));
        }

        $contents = $this->filesystem->get_contents($path);

        if ($contents === false) {
            throw new NotFoundException(message('filesystem.file_not_found', $path));
        }

        return $contents;
    }

    /**
     * Get the contents of a JSON file and decode it.
     *
     * @param mixed $path The path.
     * @param mixed $flags The flags.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function json($path, $flags = 0)
    {
        return json_decode($this->get($path), true, 512, $flags);
    }

    /**
     * Calculate the hash of a file.
     *
     * @param mixed $path The path.
     * @param mixed $algorithm The algorithm.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function hash($path, $algorithm = 'md5')
    {
        return hash_file($algorithm, $path);
    }

    /**
     * Determine if a file or directory exists.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function exists($path)
    {
        return $this->filesystem->exists($path);
    }

    /**
     * Determine if a file or directory does not exist.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function missing($path)
    {
        return !$this->exists($path);
    }

    /**
     * Get the directory name of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function dirname($path)
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Get the file type of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function type($path)
    {
        return filetype($path);
    }

    /**
     * Get the MIME type of a path.
     *
     * @param mixed $path The path.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function mime_type($path)
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }

    /**
     * Get the size of a file.
     *
     * @param mixed $path The path.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function size($path)
    {
        return $this->filesystem->size($path);
    }

    /**
     * Determine if a file is a directory.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_directory($path)
    {
        return $this->filesystem->is_dir($path);
    }

    /**
     * Determine if a file is readable.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_readable($path)
    {
        return $this->filesystem->is_readable($path);
    }

    /**
     * Determine if a file is writable.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_writable($path)
    {
        return $this->filesystem->is_writable($path);
    }

    /**
     * Get the last modified time of a file.
     *
     * @param mixed $path The path.
     *
     * @return int|false
     *
     * @since 1.0.0
     */
    public function last_modified($path)
    {
        return $this->filesystem->mtime($path);
    }

    /**
     * Make a directory.
     *
     * @param mixed $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function make_dir($path)
    {
        $path = pathinfo($path, PATHINFO_EXTENSION) !== ''
            ? $this->dirname($path)
            : $path;

        if ($this->exists($path)) {
            return true;
        }

        return wp_mkdir_p($path);
    }

    /**
     * Make a file or directory.
     *
     * @param string $path The path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function make(string $path)
    {
        if ($this->exists($path)) {
            return;
        }

        if ($this->is_directory($path)) {
            $this->make_dir($path);
            return;
        }

        return $this->filesystem->touch($path);
    }

    /**
     * Upload an {@see UploadedFile} instance to a specified directory.
     *
     * This method validates that the current user has permission to upload files, applies file name sanitization,
     * ensures the upload directory exists (creating it if necessary),
     * and then moves the uploaded file to the target location.
     *
     * @param string $path The relative subdirectory within the upload root to store the file.
     * @param UploadedFile $file The uploaded file instance to store.
     * @param string|null $name Optional. The file name to use for storage. Defaults to the original client file name.
     *
     * @return string Absolute path to the stored file on success.
     *
     * @throws AuthorizationException
     *
     * @since 1.0.0
     */
    public function upload(string $path, UploadedFile $file, $name = null)
    {
        if (!current_user_can(Capabilities::UPLOAD_FILES)) {
            throw new AuthorizationException(message('auth.upload_forbidden'));
        }

        if (is_null($name)) {
            $name = $file->get_client_original_name();
        }

        $name = Sanitizer::apply_rule($name, Sanitizer::FILE_NAME);

        $directory = $this->make_upload_directory($path);

        if ($this->missing($directory)) {
            $this->make_dir($directory);
        }

        $response = $file->move($directory, $name);

        return $response->getPathname();
    }

    /**
     * Make the upload directory.
     *
     * @param string $path The path.
     *
     * @return string
     *
     * @throws \Exception
     * @throws AuthorizationException
     *
     * @since 1.0.0
     */
    protected function make_upload_directory(string $path)
    {
        $upload_directory = wp_upload_dir()['basedir'];
        $base = realpath($upload_directory);

        if ($base === false) {
            throw new Exception(message('upload.directory_unavailable'));
        }

        $relative = Path::normalize($path);
        $directory = str_replace('\\', '/', Path::join($base, $relative));
        $base_normalized = str_replace('\\', '/', $base);

        if ($directory !== $base_normalized && !str_starts_with($directory, $base_normalized . '/')) {
            throw new AuthorizationException(message('auth.invalid_upload_path'));
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $directory);
    }
}
