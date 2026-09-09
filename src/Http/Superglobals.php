<?php
/**
 * The framework's single point of contact with PHP superglobals.
 * Unslashes on every read; sanitizes single-value reads by type, and leaves
 * whole-array reads unslashed only so callers can sanitize per field later.
 *
 * @package    Framework
 * @subpackage Http
 * @since      1.0.0
 */
namespace Framework\Http;

defined('ABSPATH') || exit;

use Framework\Sanitizer;

class Superglobals
{
    /**
     * Read from $_SERVER, or the whole array when no key is given.
     *
     * @param string|null $key The server variable name.
     * @param mixed $default The value to return when the key is absent.
     * @param mixed $type The Sanitizer::* rule to apply.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function server(?string $key = null, $default = null, $type = Sanitizer::TEXT)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase -- Unslashed and, for single-key reads, sanitized below; superglobal name is not ours to rename.
        return static::read($_SERVER, $key, $default, $type);
    }

    /**
     * Read from $_POST, or the whole array when no key is given.
     *
     * @param string|null $key The field name.
     * @param mixed $default The value to return when the key is absent.
     * @param mixed $type The Sanitizer::* rule to apply.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function post(?string $key = null, $default = null, $type = Sanitizer::TEXT)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing, Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase -- Unslashed and, for single-key reads, sanitized below. Generic accessor; nonce/CSRF verification, where required, is the responsibility of the specific route or form handler that calls this. Superglobal name is not ours to rename.
        return static::read($_POST, $key, $default, $type);
    }

    /**
     * Read from $_GET, or the whole array when no key is given.
     *
     * @param string|null $key The field name.
     * @param mixed $default The value to return when the key is absent.
     * @param mixed $type The Sanitizer::* rule to apply.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function query(?string $key = null, $default = null, $type = Sanitizer::TEXT)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended, Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase -- Unslashed and, for single-key reads, sanitized below. Generic accessor; nonce/CSRF verification, where required, is the responsibility of the specific route or form handler that calls this. Superglobal name is not ours to rename.
        return static::read($_GET, $key, $default, $type);
    }

    /**
     * Read from $_COOKIE, or the whole array when no key is given.
     *
     * @param string|null $key The cookie name.
     * @param mixed $default The value to return when the key is absent.
     * @param mixed $type The Sanitizer::* rule to apply.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function cookie(?string $key = null, $default = null, $type = Sanitizer::TEXT)
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase -- Unslashed and, for single-key reads, sanitized below; superglobal name is not ours to rename.
        return static::read($_COOKIE, $key, $default, $type);
    }

    /**
     * Read the whole $_FILES array, unslashed.
     *
     * Uploaded file metadata is structural (name/type/tmp_name/error/size), not
     * a value Sanitizer's text/email/url-style rules apply to; callers validate
     * and process it via Filesystem\UploadedFile.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function files()
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing, Framework.NamingConventions.SnakeCaseVariable.NotSnakeCase -- Unslashed below. Generic accessor; nonce/CSRF verification, where required, is the responsibility of the specific route or form handler that calls this. Superglobal name is not ours to rename.
        return static::unslash($_FILES ?? []);
    }

    /**
     * Read a value from a superglobal array, or the whole array unslashed when no key is given.
     *
     * @param array $superglobal The superglobal array to read from.
     * @param string|null $key The key to read, or null for the whole array.
     * @param mixed $default The value to return when the key is absent.
     * @param mixed $type The Sanitizer::* rule to apply to a single-key read.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected static function read(array $superglobal, ?string $key, $default, $type)
    {
        if ($key === null) {
            return static::unslash($superglobal);
        }

        if (!array_key_exists($key, $superglobal)) {
            return $default;
        }

        $value = $superglobal[$key];

        // A single-key read expects a scalar; an unexpected array (e.g. a crafted
        // "name[]=x" request) is treated as absent rather than stringified by Sanitizer.
        // The array sanitization type is the deliberate exception: an array value is
        // exactly what it expects, and Sanitizer::apply_rule() already handles it.
        if (!is_scalar($value) && $type !== Sanitizer::ARRAY) {
            return $default;
        }

        return Sanitizer::apply_rule(static::unslash($value), $type);
    }

    /**
     * Remove the slashes WordPress adds to superglobal values, recursively.
     *
     * @param mixed $value The value to unslash.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected static function unslash($value)
    {
        if (!function_exists('wp_unslash')) {
            return $value;
        }

        return wp_unslash($value);
    }
}
