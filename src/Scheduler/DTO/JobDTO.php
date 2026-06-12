<?php

namespace Framework\Scheduler\DTO;

use Framework\DTO;

class JobDTO extends DTO
{
    /**
     * The unique identifier for the job.
     *
     * @var int
     */
    public $id;

    /**
     * The arguments to be passed to the job handler.
     *
     * @var array
     */
    public $args;

    /**
     * The resolver class or method for the job.
     *
     * @var string
     */
    public $resolver;
}
