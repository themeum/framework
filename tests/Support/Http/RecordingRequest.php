<?php

namespace Framework\Tests\Support\Http;

use Framework\Http\Client\Request;

/**
 * A client Request whose pools are RecordingPool instances, exposed for assertions.
 */
class RecordingRequest extends Request
{
    /**
     * The pool created by the last pool() call.
     *
     * @var RecordingPool|null
     */
    public ?RecordingPool $pool = null;

    /**
     * Applied to each new pool before the callback runs.
     *
     * @var callable|null
     */
    public $responder;

    protected function new_pool()
    {
        $this->pool = new RecordingPool();
        $this->pool->responder = $this->responder;

        return $this->pool;
    }
}
