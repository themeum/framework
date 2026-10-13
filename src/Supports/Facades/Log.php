<?php
/**
 * Facade proxy for LogManager leveled file logging.
 * Exposes debug, info, warning, error, and emergency static methods.
 * Writes to the configured framework log file.
 *
 * @package    Framework
 * @subpackage Supports\Facades
 * @since      1.0.0
 */
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Framework\Facade;

/**
 * Facade proxy for LogManager leveled file logging.
 *
 * @method static void debug($message)
 * @method static void info($message)
 * @method static void warning($message)
 * @method static void error($message)
 * @method static void emergency($message)
 * @method static void critical($message)
 * @method static void alert($message)
 * @method static string path()
 * @see    \Framework\Managers\LogManager
 */
class Log extends Facade
{
    /**
     * Get the accessor.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function get_accessor()
    {
        return 'log';
    }
}
