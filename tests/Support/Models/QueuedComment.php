<?php

namespace Framework\Tests\Support\Models;

use Framework\Database\Query\Model;

class QueuedComment extends Model
{
    protected $table = 'test_queued_comments';

    protected $fillable = ['article_id', 'author_id', 'body'];

    public function author()
    {
        return $this->belongs_to(QueuedAuthor::class, 'author_id', 'id', 'author');
    }
}
