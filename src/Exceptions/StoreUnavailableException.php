<?php
/**
 * Exception thrown when a cache store cannot operate in the current environment.
 * Signals that the caller should fall back to another store rather than fail the request.
 *
 * @package    Framework
 * @subpackage Exceptions
 * @since      1.0.0
 */
namespace Framework\Exceptions;

defined('ABSPATH') || exit;

use RuntimeException;

class StoreUnavailableException extends RuntimeException
{
    // Custom logic can be added here if needed.
}
