<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;

/**
 * The same job without SerializesModels, so its model is frozen at dispatch.
 */
class FrozenArticleJob implements ShouldQueue
{
    use Queueable;

    public $article;

    public function __construct($article)
    {
        $this->article = $article;
    }

    public function handle()
    {
        Journal::write('handled', $this->article);
    }
}
