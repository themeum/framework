<?php

if (!function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default')
    {
        echo __($text, $domain);
    }
}

if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['framework_test_actions'][$hook_name][] = $callback;

        return true;
    }
}

if (!function_exists('framework_test_do_action')) {
    function framework_test_do_action($hook_name)
    {
        $callbacks = $GLOBALS['framework_test_actions'][$hook_name] ?? [];

        $GLOBALS['framework_test_actions'][$hook_name] = [];

        foreach ($callbacks as $callback) {
            $callback();
        }

        return count($callbacks);
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql($data)
    {
        if (is_array($data)) {
            return array_map('esc_sql', $data);
        }

        return addslashes((string) $data);
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'https://example.test' . '/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('locate_template')) {
    function locate_template($template_names, $load = false, $require_once = true, $args = [])
    {
        return '';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim(strip_tags((string) $str));
    }
}

if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule($regex, $query, $after = 'bottom')
    {
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules($hard = true)
    {
        return true;
    }
}

if (!function_exists('status_header')) {
    function status_header($code)
    {
        return true;
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers()
    {
        return true;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = [])
    {
        throw new RuntimeException((string) $message);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302)
    {
        return true;
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var($var, $default = '')
    {
        return $GLOBALS['framework_test_query_vars'][$var] ?? $default;
    }
}

if (!function_exists('is_admin')) {
    function is_admin()
    {
        return false;
    }
}

if (!function_exists('is_page')) {
    function is_page($page = '')
    {
        return (bool) ($GLOBALS['framework_test_is_page'] ?? false);
    }
}

if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id()
    {
        return (int) ($GLOBALS['framework_test_queried_object_id'] ?? 0);
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post = 0)
    {
        return 'https://example.test/?p=' . (int) $post;
    }
}

if (!function_exists('get_page_by_path')) {
    function get_page_by_path($page_path, $output = OBJECT, $post_type = 'page')
    {
        $pages = $GLOBALS['framework_test_pages'] ?? [];

        if (!isset($pages[$page_path])) {
            return null;
        }

        return (object) ['ID' => (int) $pages[$page_path]];
    }
}

if (!function_exists('wp_is_block_theme')) {
    function wp_is_block_theme()
    {
        return false;
    }
}

if (!function_exists('get_header')) {
    function get_header($name = null, $args = [])
    {
        echo '<!--header-->';
    }
}

if (!function_exists('get_footer')) {
    function get_footer($name = null, $args = [])
    {
        echo '<!--footer-->';
    }
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title, $fallback_title = '', $context = 'save')
    {
        $title = strtolower((string) $title);
        $title = preg_replace('/[^a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', $title);

        $title = trim($title, '-');

        return $title === '' ? $fallback_title : $title;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true)
    {
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);

        if ($data === 'N;') {
            return true;
        }

        if (strlen($data) < 4) {
            return false;
        }

        if ($data[1] !== ':') {
            return false;
        }

        return (bool) preg_match('/^(a|O|s|i|d|b|N):/', $data);
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data)
    {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }

        return $data;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data)
    {
        if (is_serialized($data)) {
            return @unserialize($data, ['allowed_classes' => false]);
        }

        return $data;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by($field, $value)
    {
        $users = $GLOBALS['framework_test_users'] ?? [];

        foreach ($users as $user) {
            if (isset($user[$field]) && (string) $user[$field] === (string) $value) {
                return (object) $user;
            }
        }

        return false;
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        protected $method;

        protected $route;

        protected $params;

        protected $headers;

        public function __construct(string $method = 'GET', string $route = '/test', array $params = [], array $headers = [])
        {
            $this->method = $method;
            $this->route = $route;
            $this->params = $params;
            $this->headers = $headers;
        }

        public function get_params()
        {
            return $this->params;
        }

        public function get_file_params()
        {
            return [];
        }

        public function get_method()
        {
            return $this->method;
        }

        public function get_route()
        {
            return $this->route;
        }

        public function get_headers()
        {
            return $this->headers;
        }

        public function get_url_params()
        {
            return $this->params['URL'] ?? [];
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        protected $code;

        protected $message;

        protected $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_data($code = '')
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl()
    {
        return (bool) ($GLOBALS['framework_test_is_ssl'] ?? false);
    }
}

if (!class_exists('WP_Http_Cookie')) {
    class WP_Http_Cookie
    {
        public $name;

        public $value;

        public $expires;

        public $path;

        public $domain;

        public function __construct($data, $requested_url = '')
        {
            if (is_string($data)) {
                $data = ['name' => $data, 'value' => ''];
            }

            $this->name = $data['name'] ?? '';
            $this->value = $data['value'] ?? '';
            $this->expires = $data['expires'] ?? null;
            $this->path = $data['path'] ?? null;
            $this->domain = $data['domain'] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;

        public $headers = [];

        public $status = 200;

        public function __construct($data = null, $status = 200, $headers = [])
        {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function set_data($data)
        {
            $this->data = $data;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function set_status($status)
        {
            $this->status = (int) $status;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function set_headers($headers)
        {
            $this->headers = $headers;
        }

        public function get_headers()
        {
            return $this->headers;
        }

        public function header($key, $value, $replace = true)
        {
            if ($replace || !isset($this->headers[$key])) {
                $this->headers[$key] = $value;
            }
        }
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key)
    {
        $store = $GLOBALS['framework_test_transients'] ?? [];

        if (!array_key_exists($key, $store)) {
            return false;
        }

        $entry = $store[$key];

        if (isset($entry['expires_at']) && $entry['expires_at'] !== 0 && $entry['expires_at'] <= time()) {
            unset($GLOBALS['framework_test_transients'][$key]);

            return false;
        }

        return $entry['value'];
    }
}

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0)
    {
        if (!isset($GLOBALS['framework_test_transients'])) {
            $GLOBALS['framework_test_transients'] = [];
        }

        $GLOBALS['framework_test_transients'][$key] = [
            'value' => $value,
            'lifetime' => (int) $expiration,
            'expires_at' => $expiration ? time() + (int) $expiration : 0,
        ];

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key)
    {
        if (!isset($GLOBALS['framework_test_transients'][$key])) {
            return false;
        }

        unset($GLOBALS['framework_test_transients'][$key]);

        return true;
    }
}

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache()
    {
        return (bool) ($GLOBALS['framework_test_ext_object_cache'] ?? false);
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '')
    {
        $store = $GLOBALS['framework_test_object_cache'][$group] ?? [];

        return array_key_exists($key, $store) ? $store[$key] : false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $value, $group = '', $expire = 0)
    {
        $GLOBALS['framework_test_object_cache'][$group][$key] = $value;

        return true;
    }
}

if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $value, $group = '', $expire = 0)
    {
        if (array_key_exists($key, $GLOBALS['framework_test_object_cache'][$group] ?? [])) {
            return false;
        }

        $GLOBALS['framework_test_object_cache'][$group][$key] = $value;

        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = '')
    {
        if (!isset($GLOBALS['framework_test_object_cache'][$group][$key])) {
            return false;
        }

        unset($GLOBALS['framework_test_object_cache'][$group][$key]);

        return true;
    }
}

if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '')
    {
        if (!array_key_exists($key, $GLOBALS['framework_test_object_cache'][$group] ?? [])) {
            return false;
        }

        $value = (int) $GLOBALS['framework_test_object_cache'][$group][$key] + (int) $offset;

        $GLOBALS['framework_test_object_cache'][$group][$key] = $value;

        return $value;
    }
}

if (!function_exists('wp_cache_decr')) {
    function wp_cache_decr($key, $offset = 1, $group = '')
    {
        return wp_cache_incr($key, $offset * -1, $group);
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite()
    {
        return (bool) ($GLOBALS['framework_test_multisite'] ?? false);
    }
}

if (!function_exists('get_current_network_id')) {
    function get_current_network_id()
    {
        return (int) ($GLOBALS['framework_test_network_id'] ?? 1);
    }
}

if (!function_exists('wp_get_referer')) {
    function wp_get_referer()
    {
        return $GLOBALS['framework_test_referer'] ?? false;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
        $characters = 'abcdef0123456789';
        $password = '';

        for ($index = 0; $index < $length; $index++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $password;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth')
    {
        return $GLOBALS['framework_test_salt'] ?? 'framework-test-salt';
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id()
    {
        return (int) ($GLOBALS['framework_test_blog_id'] ?? 1);
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        $store = $GLOBALS['framework_test_options'] ?? [];

        return array_key_exists($name, $store) ? $store[$name] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        if (!isset($GLOBALS['framework_test_options'])) {
            $GLOBALS['framework_test_options'] = [];
        }

        $GLOBALS['framework_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option($name, $default = false)
    {
        $store = $GLOBALS['framework_test_site_options'] ?? [];

        return array_key_exists($name, $store) ? $store[$name] : $default;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option($name, $value)
    {
        if (!isset($GLOBALS['framework_test_site_options'])) {
            $GLOBALS['framework_test_site_options'] = [];
        }

        $GLOBALS['framework_test_site_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('get_site_transient')) {
    function get_site_transient($key)
    {
        $store = $GLOBALS['framework_test_site_transients'] ?? [];

        if (!array_key_exists($key, $store)) {
            return false;
        }

        $entry = $store[$key];

        if (isset($entry['expires_at']) && $entry['expires_at'] !== 0 && $entry['expires_at'] <= time()) {
            unset($GLOBALS['framework_test_site_transients'][$key]);

            return false;
        }

        return $entry['value'];
    }
}

if (!function_exists('set_site_transient')) {
    function set_site_transient($key, $value, $expiration = 0)
    {
        if (!isset($GLOBALS['framework_test_site_transients'])) {
            $GLOBALS['framework_test_site_transients'] = [];
        }

        $GLOBALS['framework_test_site_transients'][$key] = [
            'value' => $value,
            'lifetime' => (int) $expiration,
            'expires_at' => $expiration ? time() + (int) $expiration : 0,
        ];

        return true;
    }
}

if (!function_exists('delete_site_transient')) {
    function delete_site_transient($key)
    {
        if (!isset($GLOBALS['framework_test_site_transients'][$key])) {
            return false;
        }

        unset($GLOBALS['framework_test_site_transients'][$key]);

        return true;
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir()
    {
        return ['basedir' => $GLOBALS['framework_test_upload_dir'] ?? sys_get_temp_dir()];
    }
}
