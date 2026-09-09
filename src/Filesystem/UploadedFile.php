<?php
/**
 * Represents an HTTP uploaded file extending File with original name, mime, size, and error state.
 * Supports moving to storage, JSON serialization, and WordPress media integration.
 * Created by InteractsWithFiles from the global $_FILES array.
 *
 * @package    Framework
 * @subpackage Filesystem
 * @since      1.0.0
 */
namespace Framework\Filesystem;

defined('ABSPATH') || exit;

use Exception;
use Framework\Container;
use Framework\Exceptions\AuthorizationException;
use Framework\Supports\Arr;
use JsonSerializable;

use function Framework\Polyfill\str_starts_with;
use function Framework\message;
use function Framework\throw_anyway;
use function Framework\throw_unless;

class UploadedFile extends File implements JsonSerializable
{
    /**
     * The original name of the uploaded file.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $original_name;

    /**
     * The MIME type of the uploaded file.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $mime_type;

    /**
     * The error of the uploaded file.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected int $error;

    /**
     * The original path of the uploaded file.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $original_path;

    /**
     * The size of the uploaded file.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected int $size;

    /**
     * Create a new uploaded file instance.
     *
     * @param string $path The path.
     * @param string $original_name The original name.
     * @param ?string $mime_type The mime type.
     * @param ?int $error The error.
     * @param ?int $size The size.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(
        string $path,
        string $original_name,
        ?string $mime_type = null,
        ?int $error = null,
        ?int $size = null
    ) {
        $this->original_name = $this->get_name($original_name);
        $this->original_path = strtr($original_name, '\\', '/');
        $this->mime_type = $mime_type ?? 'application/octet-stream';
        $this->error = $error ?: \UPLOAD_ERR_OK;
        $this->size = $size ?: 0;

        parent::__construct($path, $this->error === \UPLOAD_ERR_OK);
    }

    /**
     * Create a new uploaded file instance from a base file.
     *
     * @param mixed $file The file.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function create_from_base($file)
    {
        return $file instanceof static ? $file : new static(
            $file['tmp_name'],
            $file['name'],
            $file['type'],
            $file['error'],
            $file['size'],
        );
    }

    /**
     * Get the original name of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_client_original_name()
    {
        return $this->original_name;
    }

    /**
     * Get the original extension of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_client_original_extension()
    {
        return pathinfo($this->original_name, \PATHINFO_EXTENSION);
    }

    /**
     * Get the original MIME type of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_client_mime_type()
    {
        return $this->mime_type;
    }

    /**
     * Get the original path of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_client_original_path()
    {
        return $this->original_path;
    }

    /**
     * Get the error of the uploaded file.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_error()
    {
        return $this->error;
    }

    /**
     * Get the size of the uploaded file.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_size()
    {
        return $this->size;
    }

    /**
     * Store the uploaded file in a specified directory.
     *
     * @param string $path The path.
     * @param array $options The options array.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function store(string $path, array $options = [])
    {
        return $this->store_as($path, $this->get_client_original_name(), $options);
    }

    /**
     * Store the uploaded file in a specified directory with a specific name.
     *
     * @param string $path The path.
     * @param mixed $name The name.
     * @param array $options The options array.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function store_as(string $path, $name = null, array $options = [])
    {
        if (is_null($name)) {
            $name = $this->get_client_original_name();
        }

        return Container::get_instance()->make(Filesystem::class)->upload(
            $path,
            $this,
            $name,
            $options
        );
    }

    /**
     * Check if the uploaded file is valid.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_valid()
    {
        $is_ok = \UPLOAD_ERR_OK === $this->error;

        return $is_ok && is_uploaded_file($this->getPathname());
    }

    /**
     * Move the uploaded file to a new location.
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
        if ($this->is_valid()) {
            $target = $this->get_target_file($directory, $name);

            $filesystem = Container::get_instance()->make(Filesystem::class);

            $moved = $filesystem->move($this->getPathname(), $target);

            throw_unless(
                $moved,
                message('upload.move_failed', $this->getPathname(), $target),
                Exception::class
            );

            $filesystem->chmod($target, 0666 & ~umask());

            return $target;
        }

        throw_anyway($this->get_error_message());
    }

    /**
     * Get the error message of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_error_message()
    {
        return $this->error !== \UPLOAD_ERR_OK ? $this->get_exception_message() : '';
    }

    /**
     * Get the exception message of the uploaded file.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_exception_message()
    {
        $key = $this->get_message_key_for_error($this->error);

        if ($this->error === \UPLOAD_ERR_INI_SIZE) {
            return message(
                $key,
                $this->get_client_original_name(),
                $this->get_max_file_size() / 1024
            );
        }

        if (in_array($this->error, [\UPLOAD_ERR_NO_FILE, \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_EXTENSION], true)) {
            return message($key);
        }

        return message($key, $this->get_client_original_name());
    }

    /**
     * Get the message key for an upload error code.
     *
     * @param int $error_code The upload error code.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_message_key_for_error(int $error_code)
    {
        static $keys = [
            \UPLOAD_ERR_INI_SIZE => 'upload.ini_size_exceeded',
            \UPLOAD_ERR_FORM_SIZE => 'upload.form_size_exceeded',
            \UPLOAD_ERR_PARTIAL => 'upload.partial',
            \UPLOAD_ERR_NO_FILE => 'upload.no_file',
            \UPLOAD_ERR_CANT_WRITE => 'upload.cant_write',
            \UPLOAD_ERR_NO_TMP_DIR => 'upload.no_tmp_dir',
            \UPLOAD_ERR_EXTENSION => 'upload.stopped_by_extension',
        ];

        return $keys[$error_code] ?? 'upload.unknown_error';
    }

    /**
     * Get the maximum file size of the uploaded file.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function get_max_file_size()
    {
        $size_post_max = $this->parse_file_size(ini_get('post_max_size'));
        $size_upload_max = $this->parse_file_size(ini_get('upload_max_filesize'));

        return min($size_post_max ?: \PHP_INT_MAX, $size_upload_max ?: \PHP_INT_MAX);
    }

    /**
     * Parse the file size.
     *
     * @param string $size The size.
     *
     * @return int
     *
     * @since 1.0.0
     */
    protected function parse_file_size(string $size)
    {
        if ($size === '') {
            return 0;
        }

        $size = strtolower(trim($size));
        
        $max = ltrim($size, '+');

        if (str_starts_with($max, '0x')) {
            $max = intval($max, 16);
        } elseif (str_starts_with($max, '0')) {
            $max = intval($max, 8);
        } else {
            $max = intval($max);
        }

        switch (substr($size, -1)) {
            case 't':
                $max *= 1024;
                // no break
            case 'g':
                $max *= 1024;
                // no break
            case 'm':
                $max *= 1024;
                // no break
            case 'k':
                $max *= 1024;
        }

        return $max;
    }

    /**
     * Convert the uploaded file to an array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function to_array()
    {
        return [
            'path' => $this->getPathname(),
            'name' => $this->get_client_original_name(),
            'type' => $this->get_client_mime_type(),
            'error' => $this->get_error(),
            'size' => $this->get_size(),
        ];
    }

    /**
     * Convert the uploaded file to a string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function __toString()
    {
        return Arr::json_encode($this->to_array());
    }

    /**
     * Convert the uploaded file to a JSON string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }
}
