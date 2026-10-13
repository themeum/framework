<?php
/**
 * File-based logger writing leveled messages to a configurable log path.
 * Supports debug, info, warning, error, and other PSR-like severity levels with timestamps.
 * Defaults to framework.log in a guarded, unguessable directory under uploads, because the
 * plugin directory is served by the web server and the log carries exception text.
 *
 * @package    Framework
 * @subpackage Managers
 * @since      1.0.0
 */
namespace Framework\Managers;

defined('ABSPATH') || exit;

use DateTime;
use Framework\Supports\Facades\File;

use function Framework\app;
use function Framework\base_path;

class LogManager
{
    /**
     * The path given explicitly, or null to use the guarded default location.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected ?string $path;

    /**
     * The resolved log file path, kept so the uploads directory is looked up only once.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected ?string $resolved_path = null;

    /**
     * Create a new instance.
     *
     * @param ?string $path The path.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(?string $path = null)
    {
        $this->path = $path;
    }

    /**
     * Get the path of the log file.
     *
     * The default directory name carries a salted suffix, so this is the way to find the log
     * rather than guessing its location.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function path()
    {
        if (!is_null($this->resolved_path)) {
            return $this->resolved_path;
        }

        if (!is_null($this->path)) {
            return $this->resolved_path = $this->path;
        }

        return $this->resolved_path = $this->default_directory() . '/framework.log';
    }

    /**
     * Log a debug message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function debug($message)
    {
        $this->write($message, 'debug');
    }

    /**
     * Log an info message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function info($message)
    {
        $this->write($message, 'info');
    }

    /**
     * Log a warning message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function warning($message)
    {
        $this->write($message, 'warning');
    }

    /**
     * Log an error message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function error($message)
    {
        $this->write($message, 'error');
    }

    /**
     * Log an emergency message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function emergency($message)
    {
        $this->write($message, 'emergency');
    }

    /**
     * Log a critical message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function critical($message)
    {
        $this->write($message, 'critical');
    }

    /**
     * Log an alert message.
     *
     * @param mixed $message The message.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function alert($message)
    {
        $this->write($message, 'alert');
    }

    /**
     * Clear the log file.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function clear()
    {
        @file_put_contents($this->path(), '');
    }

    /**
     * Format the message.
     *
     * @param mixed $message The message.
     * @param mixed $type The type.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function format($message, $type)
    {
        return sprintf(
            "[%s] [%s] %s\n",
            (new DateTime())->format('Y-m-d H:i:s'),
            strtoupper($type),
            $message,
        );
    }

    /**
     * Write the message to the log file.
     *
     * @param mixed $message The message.
     * @param mixed $type The type.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function write($message, $type)
    {
        if (is_null($this->path)) {
            $this->ensure_default_directory();
        } else {
            File::make_dir($this->path);
        }

        @file_put_contents($this->path(), $this->format($message, $type), FILE_APPEND);
    }

    /**
     * Get the default directory the log is written beneath.
     *
     * The plugin directory is reachable over HTTP on every common server setup, so the log
     * lives under uploads instead, in a directory whose name cannot be derived without the
     * site's secrets. The application prefix keeps each consuming plugin's log separate.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function default_directory()
    {
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $base = $uploads['basedir'] ?? (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : ABSPATH . 'uploads');

        $salt = function_exists('wp_salt') ? wp_salt('nonce') : 'framework-log';
        $suffix = substr(hash_hmac('sha256', 'log-directory', $salt), 0, 12);

        return rtrim($base, '/\\') . '/' . app()->prefix() . 'logs-' . $suffix;
    }

    /**
     * Create the default directory with the files protecting it from direct web access.
     *
     * The index silences directory listing and the htaccess file is honoured by Apache; on
     * nginx the unguessable directory name is the layer that holds. Creating the directory is
     * also the moment the old log in the plugin directory stops being written, so it is
     * removed here rather than left readable.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function ensure_default_directory()
    {
        $directory = dirname($this->path());

        if (File::is_directory($directory)) {
            return;
        }

        File::make_dir($directory);
        File::put($directory . '/index.php', "<?php\n// Silence is golden.\n");
        File::put($directory . '/.htaccess', "Require all denied\n");

        $legacy = base_path('framework.log');

        if (File::exists($legacy)) {
            File::delete([$legacy], false);
        }
    }
}
