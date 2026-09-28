<?php
/**
 * Dispatched after a job has been written to the queue table.
 * Carries the new row's id and the job instance as it was dispatched.
 *
 * @package    Framework
 * @subpackage Queue\Events
 * @since      3.2.0
 */
namespace Framework\Queue\Events;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;

class JobQueued
{
    /**
     * The id of the stored row.
     *
     * @var int
     *
     * @since 3.2.0
     */
    public $id;

    /**
     * The job that was queued.
     *
     * @var \Framework\Contracts\ShouldQueue
     *
     * @since 3.2.0
     */
    public $job;

    /**
     * Create a new event instance.
     *
     * @param int $id The id of the stored row.
     * @param \Framework\Contracts\ShouldQueue $job The job that was queued.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(int $id, ShouldQueue $job)
    {
        $this->id = $id;
        $this->job = $job;
    }
}
