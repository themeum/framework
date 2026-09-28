<?php

namespace Framework\Tests\Support\Queue;

/**
 * Stands in for the log manager so failures can be asserted without writing a log file.
 */
class RecordingLogger
{
    public array $errors = [];

    public function error($message)
    {
        $this->errors[] = $message;
    }
}
