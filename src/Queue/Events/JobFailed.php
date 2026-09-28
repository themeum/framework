<?php
/**
 * Dispatched when a job is moved to the failed jobs table.
 * The job instance is null when the payload itself could not be restored.
 *
 * @package    Framework
 * @subpackage Queue\Events
 * @since      3.2.0
 */
namespace Framework\Queue\Events;

defined('ABSPATH') || exit;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\JobRecord;
use Throwable;

class JobFailed
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
     * The job instance, or null when its payload could not be restored.
     *
     * @var \Framework\Contracts\ShouldQueue|null
     *
     * @since 3.2.0
     */
    public $job;

    /**
     * The exception that caused the failure.
     *
     * @var \Throwable
     *
     * @since 3.2.0
     */
    public $exception;

    /**
     * Create a new event instance.
     *
     * @param \Framework\Queue\JobRecord $record The claimed record.
     * @param \Framework\Contracts\ShouldQueue|null $job The job instance, if it was restored.
     * @param \Throwable $exception The exception that caused the failure.
     *
     * @return void
     *
     * @since 3.2.0
     */
    public function __construct(JobRecord $record, ?ShouldQueue $job, Throwable $exception)
    {
        $this->record = $record;
        $this->job = $job;
        $this->exception = $exception;
    }
}
