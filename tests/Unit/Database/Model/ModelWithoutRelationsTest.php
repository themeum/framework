<?php

namespace Framework\Tests\Unit\Database\Model;

use Framework\Database\Query\Collection;
use Framework\Tests\Support\Models\QueuedArticle;
use Framework\Tests\Unit\TestCase;

class ModelWithoutRelationsTest extends TestCase
{
    public function test_without_relations_returns_a_copy_with_no_relations(): void
    {
        $article = new QueuedArticle(['title' => 'First']);
        $article->set_relation('comments', new Collection([]));

        $copy = $article->without_relations();

        $this->assertNotSame($article, $copy);
        $this->assertSame('First', $copy->title);
        $this->assertSame([], $copy->get_relations());
        $this->assertTrue($article->relation_loaded('comments'));
    }
}
