<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

class SendEmail implements ShouldQueue
{
    use Queueable;

    public $user_id;

    public $subject;

    public $tags;

    public function __construct($user_id, string $subject = '', array $tags = [])
    {
        $this->user_id = $user_id;
        $this->subject = $subject;
        $this->tags = $tags;
    }

    public function handle()
    {
        Journal::write('handled', $this->user_id);

        return 'sent:' . $this->user_id;
    }
}
