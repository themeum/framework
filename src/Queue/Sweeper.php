<?php
/**
 * The every-minute WP-Cron callback that notices due jobs and starts a worker for them.
 * It deliberately does no job work itself: it runs inside whichever request WP-Cron piggybacks
 * on, so it only asks whether anything is due and, if so, fires the non-blocking loopback.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.0
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

class Sweeper
{
    /**
     * The queue storage.
     *
     * @var \Framework\Queue\DatabaseQueue
     *
     * @since 3.2.0
     */
    protected $queue;

    /**
     * The loopback spawner.
     *
     * @var \Framework\Queue\Spawner
     *
     * @since 3.2.0
     */
    protected $spawner;

    /**
     * Create a sweeper.
     *
     * @param \Framework\Queue\DatabaseQueue $queue The queue storage.
     * @param \Framework\Queue\Spawner $spawner The loopback spawner.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(DatabaseQueue $queue, Spawner $spawner)
    {
        $this->queue = $queue;
        $this->spawner = $spawner;
    }

    /**
     * Start a worker when jobs are due and no chain is running.
     *
     * @return bool Whether a worker was started.
     *
     * @since 3.2.0
     */
    public function sweep()
    {
        if (!$this->queue->table_exists() || !$this->queue->has_due()) {
            return false;
        }

        return $this->spawner->spawn_if_idle();
    }
}
