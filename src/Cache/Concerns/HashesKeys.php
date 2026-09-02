<?php
/**
 * Key derivation shared by the cache stores.
 * Hashes every key so that its length and characters can never affect where or whether it is
 * stored, and scopes it to the current site so a network install cannot read across sites.
 *
 * @package    Framework
 * @subpackage Cache\Concerns
 * @since      1.0.0
 */
namespace Framework\Cache\Concerns;

defined('ABSPATH') || exit;

trait HashesKeys
{
    /**
     * Whether this store is shared across every site of a network.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $network = false;

    /**
     * The resolved secret used to derive key hashes.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $resolved_key_salt = null;

    /**
     * Derive the storage identifier for a cache key.
     *
     * @param string $key The cache key.
     * @param int $length The number of hexadecimal characters to keep.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function hash_key(string $key, int $length = 64)
    {
        $hash = hash_hmac('sha256', $this->key_scope() . '|' . $key, $this->key_salt());

        return substr($hash, 0, $length);
    }

    /**
     * Get the secret used to derive key hashes.
     *
     * Resolved on first use rather than in a constructor, because wp_salt() is declared in
     * pluggable.php, which WordPress loads after plugins.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function key_salt()
    {
        if (is_null($this->resolved_key_salt)) {
            $this->resolved_key_salt = function_exists('wp_salt') ? wp_salt('nonce') : 'framework-cache';
        }

        return $this->resolved_key_salt;
    }

    /**
     * Get the scope keys are derived within.
     *
     * A network wide store shares one scope; every other store is scoped to the current site so
     * that switching sites during a request cannot surface another site's values.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function key_scope()
    {
        if ($this->network) {
            return 'network';
        }

        return function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '0';
    }
}
