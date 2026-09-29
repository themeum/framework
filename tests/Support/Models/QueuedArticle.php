<?php

namespace Framework\Tests\Support\Models;

use Framework\Database\Query\Model;

class QueuedArticle extends Model
{
    protected $table = 'test_queued_articles';

    protected $fillable = ['title', 'body'];

    public function comments()
    {
        return $this->has_many(QueuedComment::class, 'article_id');
    }
}
