<?php
/**
 * Dispatched after a queued job's handle method returned without throwing.
 * Not dispatched when the job released or failed itself from inside handle.
 *
 * @package    Framework
 * @subpackage Queue\Events
 * @since      3.2.0
 */
namespace Framework\Queue\Events;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\JobRecord;

class JobProcessed
{
    /**
     * The claimed record.
     *
     * @var \Framework\Queue\JobRecord
     *
     * @since 3.2.0
     */
    public $record;

    /**
     * The job instance that ran.
     *
     * @var \Framework\Contracts\ShouldQueue
     *
     * @since 3.2.0
     */
    public $job;

    /**
     * Create a new event instance.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     * @param \Framework\Contracts\ShouldQueue $job The job instance that ran.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(JobRecord $record, ShouldQueue $job)
    {
        $this->record = $record;
        $this->job = $job;
    }
}
