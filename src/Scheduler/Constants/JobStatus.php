<?php

namespace Framework\Scheduler\Constants;

class JobStatus
{
    /**
     * The job is pending execution.
     */
    const PENDING = 'pending';

    /**
     * The job is currently being processed.
     */
    const PROCESSING = 'processing';

    /**
     * The job has failed to complete.
     */
    const FAILED = 'failed';

    /**
     * The job has completed successfully.
     */
    const COMPLETED = 'completed';
}
