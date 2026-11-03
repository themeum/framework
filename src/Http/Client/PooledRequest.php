<?php
/**
 * A request built inside Http::pool(). It inherits every fluent method of Request
 * and differs only at dispatch: the verb records the built URL and arguments
 * instead of sending them, so the pool can send them all together.
 *
 * @package    Framework
 * @subpackage Http\Client
 * @since      3.3.0
 */
namespace Framework\Http\Client;

defined('ABSPATH') || exit;

/**
 * A pending request whose verb methods record rather than send.
 *
 * Inside a pool, get(), post() and the other verbs return this pooled request,
 * not a Response. The pool's return value carries the responses.
 *
 * @since 3.3.0
 */
class PooledRequest extends Request
{
    /**
     * The built URL and arguments, set once a verb method has been called.
     *
     * @var array|null
     *
     * @since 3.3.0
     */
    protected ?array $recorded = null;

    /**
     * Record the built request instead of sending it.
     *
     * @param string $url The final url.
     * @param array $args The request arguments.
     *
     * @return $this
     *
     * @since 3.3.0
     */
    protected function dispatch(string $url, array $args)
    {
        $this->recorded = [$url, $args];

        return $this;
    }

    /**
     * Determine if a verb method has recorded this request.
     *
     * @return bool
     *
     * @since 3.3.0
     */
    public function is_recorded()
    {
        return $this->recorded !== null;
    }

    /**
     * Get the recorded URL and arguments as a [$url, $args] pair.
     *
     * @return array|null
     *
     * @since 3.3.0
     */
    public function get_recorded()
    {
        return $this->recorded;
    }
}
