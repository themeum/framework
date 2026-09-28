<?php
/**
 * Both ends of the loopback: sends the signed, non-blocking request that starts a worker, and
 * handles that request when it arrives.
 * One worker chain runs at a time. Each worker holds the chain lock for its whole run and
 * releases it just before spawning its successor, which must acquire it again. Handing the lock
 * across the hop is avoided on purpose: the options-table lock can only be released by the
 * instance that acquired it. The cost is a narrow window in which a second worker may be
 * spawned; it fails to acquire and exits, and claims are atomic, so no job runs twice.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

use Framework\Cache\CacheManager;
use Framework\Cache\Concerns\InteractsWithTime;
use Framework\Supports\Facades\Http;
use Throwable;

use function Framework\app;

class Spawner
{
    use InteractsWithTime;

    /**
     * How far a request's timestamp may be from now, in seconds, for its signature to be accepted.
     *
     * @var int
     *
     * @since 3.2.0
     */
    public const SIGNATURE_WINDOW = 60;

    /**
     * The queue storage.
     *
     * @var \Framework\Queue\DatabaseQueue
     *
     * @since 3.2.0
     */
    protected $queue;

    /**
     * The worker that processes jobs.
     *
     * @var \Framework\Queue\Worker
     *
     * @since 3.2.0
     */
    protected $worker;

    /**
     * Create a spawner.
     *
     * @param \Framework\Queue\DatabaseQueue $queue The queue storage.
     * @param \Framework\Queue\Worker $worker The worker that processes jobs.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(DatabaseQueue $queue, Worker $worker)
    {
        $this->queue = $queue;
        $this->worker = $worker;
    }

    /**
     * Get the AJAX action the worker endpoint listens on.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public static function action()
    {
        return app()->prefix() . 'queue_work';
    }

    /**
     * Start a worker unless a chain is already running.
     *
     * @return bool Whether a worker was started.
     *
     * @since 3.2.0
     */
    public function spawn_if_idle()
    {
        $lock = $this->lock();

        if (!$lock->acquire()) {
            return false;
        }

        $lock->release();
        $this->spawn();

        return true;
    }

    /**
     * Send the signed, non-blocking loopback request that starts a worker.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function spawn()
    {
        $timestamp = (int) $this->current_timestamp();

        try {
            Http::as_form()
                ->timeout(0.01)
                ->with_options([
                    'blocking' => false,
                    'sslverify' => apply_filters('https_local_ssl_verify', false),
                ])
                ->post(admin_url('admin-ajax.php'), [
                    'action' => static::action(),
                    'ts' => $timestamp,
                    'sig' => $this->sign($timestamp),
                ]);
        } catch (Throwable $exception) {
            // A spawn that cannot be sent is retried by the next sweep; it must not break the request.
        }
    }

    /**
     * Handle an incoming worker request.
     *
     * @param array $input The request body.
     *
     * @return bool Whether the request was accepted and a worker ran.
     *
     * @since 3.2.0
     */
    public function handle_request(array $input)
    {
        if (!$this->verify($input)) {
            return false;
        }

        $lock = $this->lock();

        if (!$lock->acquire()) {
            return false;
        }

        $this->prepare_runtime();

        try {
            $remaining = $this->worker->run();
        } catch (Throwable $exception) {
            $remaining = true;
        }

        $lock->release();

        if ($remaining) {
            $this->spawn();
        }

        return true;
    }

    /**
     * Sign a worker request.
     *
     * @param int $timestamp The request timestamp.
     *
     * @return string
     *
     * @since 3.2.0
     */
    public function sign(int $timestamp)
    {
        return hash_hmac('sha256', static::action() . '|' . $timestamp, wp_salt('auth'));
    }

    /**
     * Verify a worker request's signature and freshness.
     *
     * @param array $input The request body.
     *
     * @return bool
     *
     * @since 3.2.0
     */
    public function verify(array $input)
    {
        if (!isset($input['ts'], $input['sig']) || !is_scalar($input['ts']) || !is_string($input['sig'])) {
            return false;
        }

        $timestamp = (int) $input['ts'];

        if (abs((int) $this->current_timestamp() - $timestamp) > static::SIGNATURE_WINDOW) {
            return false;
        }

        return hash_equals($this->sign($timestamp), $input['sig']);
    }

    /**
     * Get the lock that allows one worker chain at a time.
     *
     * It outlives a worker's budget by a margin, so a worker that dies leaves it to expire.
     *
     * @return \Framework\Contracts\Lock
     *
     * @since 3.2.0
     */
    protected function lock()
    {
        return app(CacheManager::class)->lock(
            app()->prefix() . 'queue_chain',
            (int) ceil($this->worker->budget()) + 60
        );
    }

    /**
     * Keep the worker running after the loopback caller disconnects, with room for its budget.
     *
     * @return void
     *
     * @since 3.2.0
     */
    protected function prepare_runtime()
    {
        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit((int) ceil($this->worker->budget()) + 30);
        }
    }
}
