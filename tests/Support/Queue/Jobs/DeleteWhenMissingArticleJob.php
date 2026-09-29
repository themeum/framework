<?php

namespace Framework\Tests\Support\Queue\Jobs;

class DeleteWhenMissingArticleJob extends ArticleJob
{
    public $delete_when_missing_models = true;
}
