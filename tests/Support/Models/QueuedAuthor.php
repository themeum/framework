<?php

namespace Framework\Tests\Support\Models;

use Framework\Database\Query\Model;

class QueuedAuthor extends Model
{
    protected $table = 'test_queued_authors';

    protected $fillable = ['name'];
}
