<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use Framework\Queue\Concerns\SerializesModels;

/**
 * Holds whatever it is given on a public property and records it when handled.
 */
class ArticleJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public $article;

    public function __construct($article)
    {
        $this->article = $article;
    }

    public function handle()
    {
        Journal::write('handled', $this->article);
    }

    public function failed($exception)
    {
        Journal::write('failed', $exception);
    }
}
