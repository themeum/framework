<?php

namespace Framework\Tests\Support\Queue\Jobs;

use Framework\Tests\Support\Models\QueuedArticle;

/**
 * Holds models on every visibility, plus values the trait must leave alone.
 */
class VisibilityJob extends BaseVisibilityJob
{
    public $public_article;

    protected $protected_article;

    private $private_article;

    public $nested = [];

    public $scalars = [];

    public QueuedArticle $never_assigned;

    public function __construct($article, array $nested = [], array $scalars = [])
    {
        parent::__construct($article);

        $this->public_article = $article;
        $this->protected_article = $article;
        $this->private_article = $article;
        $this->nested = $nested;
        $this->scalars = $scalars;
    }

    public function handle()
    {
        Journal::write('handled', $this);
    }

    public function protected_article()
    {
        return $this->protected_article;
    }

    public function private_article()
    {
        return $this->private_article;
    }

    public function is_never_assigned_initialized()
    {
        return (new \ReflectionProperty($this, 'never_assigned'))->isInitialized($this);
    }
}
