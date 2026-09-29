<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Contracts\ShouldQueue;
use Framework\Queue\Concerns\Queueable;
use Framework\Queue\Concerns\SerializesModels;

/**
 * Declares a private model property of its own, which a subclass cannot see through reflection.
 */
abstract class BaseVisibilityJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    private $parent_article;

    public function __construct($article)
    {
        $this->parent_article = $article;
    }

    public function parent_article()
    {
        return $this->parent_article;
    }
}
