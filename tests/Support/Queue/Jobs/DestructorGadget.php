<?php

namespace Framework\Tests\Support\Queue\Jobs;

/**
 * Stands in for a gadget class: it records when it is created or released so a test can prove
 * that a tampered payload never got that far.
 */
class DestructorGadget
{
    public $command = '';

    public function __wakeup()
    {
        Journal::write('gadget woke');
    }

    public function __destruct()
    {
        Journal::write('gadget destructed');
    }
}
