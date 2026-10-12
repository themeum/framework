<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

/**
 * Carries arbitrary values on one property, and declares the one value object it allows.
 */
class ValueJob implements ShouldQueue
{
    use Queueable;

    protected $allowed_classes = [ValueObject::class];

    public $value;

    public int $count = 0;

    public function __construct($value = null)
    {
        $this->value = $value;
    }

    public function handle()
    {
        Journal::write('handled', $this->value);
    }
}
