<?php
/**
 * Dispatched immediately before a queued job's handle method runs.
 * Carries the claimed record and the restored job instance.
 *
 * @package    Framework
 * @subpackage Queue\Events
 * @since      3.2.0
 */
namespace Framework\Queue\Events;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\JobRecord;

class JobProcessing
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
     * The restored job instance.
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
     * @param \Framework\Contracts\ShouldQueue $job The restored job instance.
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
