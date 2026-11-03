<?php
/**
 * Facade proxy for the HTTP client Request builder.
 * Exposes get, post, with_token, as_json, and other fluent request methods.
 * Entry point for outbound HTTP integrations.
 *
 * @package    Framework
 * @subpackage Supports\Facades
 * @since      1.0.0
 */
namespace Framework\Supports\Facades;

defined('ABSPATH') || exit;

use Framework\Facade;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Facade proxy for the HTTP client Request builder.
 *
 * @method static \Framework\Http\Client\Response get(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response post(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response put(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response patch(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response delete(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response head(string $url, array $options = [])
 * @method static \Framework\Http\Client\Response options(string $url, array $options = [])
 * @method static \Framework\Http\Client\Request with_headers(array $headers)
 * @method static \Framework\Http\Client\Request with_url_parameters(array $parameters)
 * @method static \Framework\Http\Client\Request with_body($content, $type = 'application/json')
 * @method static \Framework\Http\Client\Request with_token(string $token, $type = 'Bearer')
 * @method static \Framework\Http\Client\Request with_user_agent($user_agent)
 * @method static \Framework\Http\Client\Request without_verifying()
 * @method static \Framework\Http\Client\Request accept($value)
 * @method static \Framework\Http\Client\Request accept_json()
 * @method static \Framework\Http\Client\Request body_format(string $format)
 * @method static \Framework\Http\Client\Request as_json()
 * @method static \Framework\Http\Client\Request as_form()
 * @method static \Framework\Http\Client\Request as_multipart()
 * @method static \Framework\Http\Client\Request method(string $method)
 * @method static \Framework\Http\Client\Request attach(string $name, string $content, ?string $filename = null, array $headers = [])
 * @method static \Framework\Http\Client\Request macro($name, callable $macro)
 * @method static array pool(callable $callback, array $options = [])
 * @see    \Framework\Http\Client\Request
 */

class Http extends Facade
{
    /**
     * The is cacheable.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected static $is_cacheable = false;

    /**
     * Get the accessor.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function get_accessor()
    {
        return 'client-request';
    }
}
