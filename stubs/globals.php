<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/var/www/html/');
    }

    if (!defined('WP_CLI')) {
        define('WP_CLI', false);
    }

    if (!defined('FS_CHMOD_FILE')) {
        define('FS_CHMOD_FILE', 0644);
    }

    if (!defined('REST_REQUEST')) {
        define('REST_REQUEST', false);
    }

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 1);
    }

    if (!defined('YEAR_IN_SECONDS')) {
        define('YEAR_IN_SECONDS', 365 * 24 * 60 * 60);
    }
}

namespace {
    global $wpdb;

    if (!isset($wpdb)) {
        $wpdb = new wpdb('', '', '', '');
    }
}

namespace {
    if (!function_exists('site_url')) {
        /**
         * @param string $path
         * @param string|null $scheme
         * @return string
         */
        function site_url($path = '', $scheme = null)
        {
            return '';
        }
    }

    if (!function_exists('get_user_by')) {
        /**
         * @param string $field
         * @param int|string $value
         * @return \WP_User|false
         */
        function get_user_by($field, $value)
        {
            return false;
        }
    }

    if (!function_exists('wp_get_current_user')) {
        /**
         * @return \WP_User
         */
        function wp_get_current_user()
        {
            return new \WP_User();
        }
    }

    if (!function_exists('get_user_meta')) {
        /**
         * @param int $user_id
         * @param string $key
         * @param bool $single
         * @return mixed
         */
        function get_user_meta($user_id, $key = '', $single = false)
        {
            return $single ? '' : [];
        }
    }

    if (!function_exists('get_avatar_url')) {
        /**
         * @param int|string|\WP_User $id_or_email
         * @param array $args
         * @return string|false
         */
        function get_avatar_url($id_or_email, $args = null)
        {
            return '';
        }
    }

    if (!function_exists('is_user_logged_in')) {
        /**
         * @return bool
         */
        function is_user_logged_in()
        {
            return false;
        }
    }

    if (!function_exists('get_option')) {
        /**
         * @param string $option
         * @param mixed $default
         * @return mixed
         */
        function get_option($option, $default = false)
        {
            return $default;
        }
    }

    if (!function_exists('update_option')) {
        /**
         * @param string $option
         * @param mixed $value
         * @param bool|null $autoload
         * @return bool
         */
        function update_option($option, $value, $autoload = null)
        {
            return true;
        }
    }

    if (!function_exists('delete_option')) {
        /**
         * @param string $option
         * @return bool
         */
        function delete_option($option)
        {
            return true;
        }
    }

    if (!function_exists('maybe_serialize')) {
        /**
         * @param mixed $data
         * @return string
         */
        function maybe_serialize($data)
        {
            return '';
        }
    }

    if (!function_exists('maybe_unserialize')) {
        /**
         * @param string $data
         * @return mixed
         */
        function maybe_unserialize($data)
        {
            return '';
        }
    }
}
