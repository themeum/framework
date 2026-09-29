<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Database\Connection\Connection;
use Framework\Database\Query\Collection;
use Framework\Exceptions\ModelNotFoundException;
use Framework\Exceptions\QueueException;
use Framework\Queue\Events\JobFailed;
use Framework\Queue\ModelIdentifier;
use Framework\Queue\Payload;
use Framework\Tests\Support\Database\ModelTestWpdb;
use Framework\Tests\Support\Models\QueuedArticle;
use Framework\Tests\Support\Models\QueuedAuthor;
use Framework\Tests\Support\Models\QueuedComment;
use Framework\Tests\Support\Queue\Jobs\ArticleJob;
use Framework\Tests\Support\Queue\Jobs\DeleteWhenMissingArticleJob;
use Framework\Tests\Support\Queue\Jobs\FrozenArticleJob;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\Jobs\SendEmail;
use Framework\Tests\Support\Queue\Jobs\VisibilityJob;
use LogicException;

class SerializesModelsTest extends QueueTestCase
{
    protected ModelTestWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $wpdb = new ModelTestWpdb(['prefix' => 'wp_']);
        $this->wpdb = $wpdb;
        $this->app->instance(Connection::class, new Connection());

        $this->wpdb->seed('test_queued_articles', [
            ['id' => 1, 'title' => 'First', 'body' => str_repeat('long body ', 50)],
            ['id' => 2, 'title' => 'Second', 'body' => 'short'],
            ['id' => 3, 'title' => 'Third', 'body' => 'short'],
        ]);
        $this->wpdb->seed('test_queued_comments', [
            ['id' => 10, 'article_id' => 1, 'author_id' => 100, 'body' => 'Nice'],
            ['id' => 11, 'article_id' => 1, 'author_id' => 101, 'body' => 'Agreed'],
        ]);
        $this->wpdb->seed('test_queued_authors', [
            ['id' => 100, 'name' => 'Ada'],
            ['id' => 101, 'name' => 'Grace'],
        ]);

        $this->enable_queue();
    }

    public function test_a_saved_model_is_stored_as_its_identity_without_attributes(): void
    {
        ArticleJob::dispatch(QueuedArticle::find(1));

        $data = Payload::decode($this->only_row()['payload'])['data'];

        $this->assertStringContainsString(QueuedArticle::class, $data);
        $this->assertStringContainsString(ModelIdentifier::class, $data);
        $this->assertStringNotContainsString('long body', $data);
    }

    public function test_a_saved_model_is_restored_fresh_from_the_database(): void
    {
        ArticleJob::dispatch(QueuedArticle::find(1));
        $this->update_row('test_queued_articles', 1, ['title' => 'Edited']);

        $this->worker->run();

        $article = $this->handled();
        $this->assertInstanceOf(QueuedArticle::class, $article);
        $this->assertSame('Edited', $article->title);
        $this->assertTrue($this->model_exists($article));
    }

    public function test_a_job_without_the_trait_keeps_the_model_frozen(): void
    {
        FrozenArticleJob::dispatch(QueuedArticle::find(1));
        $this->update_row('test_queued_articles', 1, ['title' => 'Edited']);

        $this->worker->run();

        $this->assertSame('First', $this->handled()->title);
    }

    public function test_models_on_every_visibility_are_restored_fresh(): void
    {
        VisibilityJob::dispatch(QueuedArticle::find(1));
        $this->update_row('test_queued_articles', 1, ['title' => 'Edited']);

        $this->worker->run();

        $job = $this->handled();
        $this->assertSame('Edited', $job->public_article->title);
        $this->assertSame('Edited', $job->protected_article()->title);
        $this->assertSame('Edited', $job->private_article()->title);
        $this->assertSame('Edited', $job->parent_article()->title);
    }

    public function test_other_values_and_nested_models_are_stored_as_they_are(): void
    {
        $scalars = ['int' => 42, 'string' => 'hello', 'array' => [1, 2], 'object' => (object) ['a' => 1]];

        VisibilityJob::dispatch(QueuedArticle::find(1), ['article' => QueuedArticle::find(2)], $scalars);
        $this->update_row('test_queued_articles', 2, ['title' => 'Edited']);

        $this->worker->run();

        $job = $this->handled();
        $this->assertEquals($scalars, $job->scalars);
        $this->assertSame('Second', $job->nested['article']->title);
        $this->assertFalse($job->is_never_assigned_initialized());
    }

    public function test_an_unsaved_model_is_stored_as_it_is(): void
    {
        ArticleJob::dispatch(new QueuedArticle(['title' => 'Draft']));

        $this->worker->run();

        $article = $this->handled();
        $this->assertSame('Draft', $article->title);
        $this->assertFalse($this->model_exists($article));
    }

    public function test_loaded_relations_are_reloaded_fresh(): void
    {
        $article = QueuedArticle::find(1);
        $article->load('comments');

        ArticleJob::dispatch($article);
        $this->wpdb->table_data['wp_test_queued_comments'][] = [
            'id' => 12, 'article_id' => 1, 'author_id' => 100, 'body' => 'Late',
        ];

        $this->worker->run();

        $restored = $this->handled();
        $this->assertTrue($restored->relation_loaded('comments'));
        $this->assertSame([10, 11, 12], $this->keys($restored->get_relation('comments')));
    }

    public function test_nested_relations_are_reloaded(): void
    {
        $article = QueuedArticle::find(1);
        $article->load('comments.author');

        ArticleJob::dispatch($article);
        $this->update_row('test_queued_authors', 100, ['name' => 'Ada L.']);

        $this->worker->run();

        $comments = $this->handled()->get_relation('comments');
        $this->assertTrue($comments->first()->relation_loaded('author'));
        $this->assertSame('Ada L.', $comments->first()->get_relation('author')->name);
    }

    public function test_no_relations_are_loaded_when_none_were_loaded_at_dispatch(): void
    {
        ArticleJob::dispatch(QueuedArticle::find(1));

        $this->worker->run();

        $this->assertSame([], $this->handled()->get_relations());
    }

    public function test_without_relations_opts_a_model_out_of_relation_reloading(): void
    {
        $article = QueuedArticle::find(1);
        $article->load('comments');

        ArticleJob::dispatch($article->without_relations());

        $this->worker->run();

        $this->assertSame([], $this->handled()->get_relations());
    }

    public function test_a_collection_is_restored_fresh_in_its_original_order(): void
    {
        $articles = new Collection([QueuedArticle::find(3), QueuedArticle::find(1), QueuedArticle::find(2)]);

        ArticleJob::dispatch($articles);
        $this->update_row('test_queued_articles', 1, ['title' => 'Edited']);

        $this->worker->run();

        $restored = $this->handled();
        $this->assertInstanceOf(Collection::class, $restored);
        $this->assertSame([3, 1, 2], $this->keys($restored));
        $this->assertSame('Edited', $restored->all()[1]->title);
    }

    public function test_deleted_rows_are_dropped_from_a_restored_collection(): void
    {
        ArticleJob::dispatch(new Collection([QueuedArticle::find(3), QueuedArticle::find(1), QueuedArticle::find(2)]));
        $this->delete_row('test_queued_articles', 1);

        $this->worker->run();

        $this->assertSame([3, 2], $this->keys($this->handled()));
    }

    public function test_an_empty_collection_is_restored_empty(): void
    {
        ArticleJob::dispatch(new Collection([]));

        $this->worker->run();

        $restored = $this->handled();
        $this->assertInstanceOf(Collection::class, $restored);
        $this->assertSame([], $restored->all());
    }

    public function test_a_collection_with_an_unsaved_model_is_stored_as_it_is(): void
    {
        ArticleJob::dispatch(new Collection([QueuedArticle::find(1), new QueuedArticle(['title' => 'Draft'])]));
        $this->update_row('test_queued_articles', 1, ['title' => 'Edited']);

        $this->worker->run();

        $restored = $this->handled()->all();
        $this->assertSame('First', $restored[0]->title);
        $this->assertSame('Draft', $restored[1]->title);
    }

    public function test_a_collection_of_mixed_model_classes_cannot_be_dispatched(): void
    {
        try {
            ArticleJob::dispatch(new Collection([QueuedArticle::find(1), QueuedAuthor::find(100)]));
            $this->fail('A mixed collection was dispatched.');
        } catch (LogicException $exception) {
            $this->assertSame('Queueing collections with multiple model types is not supported.', $exception->getMessage());
        }

        $this->assertCount(0, $this->queue->rows);
    }

    public function test_a_missing_model_fails_the_job_on_its_first_attempt(): void
    {
        $this->events->listen_for(JobFailed::class);

        ArticleJob::dispatch(QueuedArticle::find(2));
        SendEmail::dispatch('next');
        $this->delete_row('test_queued_articles', 2);

        $this->worker->run();

        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(1, $this->queue->failed);

        $failure = array_values($this->queue->failed)[0]['exception'];
        $this->assertStringContainsString(ModelNotFoundException::class, $failure);
        $this->assertStringContainsString(QueuedArticle::class, $failure);

        $this->assertSame([JobFailed::class], $this->events->dispatched_classes());
        $this->assertCount(1, $this->log->errors);
        $this->assertSame([], Journal::values('failed'));
        $this->assertSame(['next'], Journal::values('handled'));
    }

    public function test_a_missing_model_deletes_the_job_when_it_asks_for_that(): void
    {
        $this->events->listen_for(JobFailed::class);

        DeleteWhenMissingArticleJob::dispatch(QueuedArticle::find(2));
        SendEmail::dispatch('next');
        $this->delete_row('test_queued_articles', 2);

        $this->worker->run();

        $this->assertCount(0, $this->queue->rows);
        $this->assertCount(0, $this->queue->failed);
        $this->assertSame([], $this->events->dispatched_classes());
        $this->assertSame([], $this->log->errors);
        $this->assertSame(['next'], Journal::values('handled'));
    }

    public function test_a_tampered_model_class_fails_the_job_without_being_instantiated(): void
    {
        ArticleJob::dispatch(QueuedArticle::find(1));

        $row = $this->only_row();
        $envelope = Payload::decode($row['payload']);
        $envelope['data'] = str_replace(
            's:' . strlen(QueuedArticle::class) . ':"' . QueuedArticle::class . '"',
            's:' . strlen(SendEmail::class) . ':"' . SendEmail::class . '"',
            $envelope['data']
        );
        $this->queue->rows[$row['id']]['payload'] = wp_json_encode($envelope);

        $this->worker->run();

        $this->assertCount(1, $this->queue->failed);
        $failure = array_values($this->queue->failed)[0]['exception'];
        $this->assertStringContainsString(QueueException::class, $failure);
        $this->assertStringContainsString('the stored model class [' . SendEmail::class . ']', $failure);
        $this->assertSame([], Journal::values('handled'));
    }

    public function test_restoring_directly_throws_when_the_model_is_missing(): void
    {
        ArticleJob::dispatch(QueuedArticle::find(1));
        $this->delete_row('test_queued_articles', 1);

        $this->expectException(ModelNotFoundException::class);

        Payload::restore(Payload::decode($this->only_row()['payload']));
    }

    protected function only_row(): array
    {
        $this->assertCount(1, $this->queue->rows);

        return array_values($this->queue->rows)[0];
    }

    protected function handled()
    {
        $values = Journal::values('handled');
        $this->assertCount(1, $values);

        return $values[0];
    }

    protected function keys($collection): array
    {
        return array_map(function ($model) {
            return (int) $model->get_primary_key_value();
        }, array_values($collection->all()));
    }

    protected function model_exists($model): bool
    {
        $property = new \ReflectionProperty($model, 'exists');
        $property->setAccessible(true);

        return $property->getValue($model);
    }

    protected function update_row(string $table, int $id, array $values): void
    {
        foreach ($this->wpdb->table_data['wp_' . $table] as $index => $row) {
            if ((int) $row['id'] === $id) {
                $this->wpdb->table_data['wp_' . $table][$index] = array_merge($row, $values);
            }
        }
    }

    protected function delete_row(string $table, int $id): void
    {
        $this->wpdb->table_data['wp_' . $table] = array_values(array_filter(
            $this->wpdb->table_data['wp_' . $table],
            function (array $row) use ($id) {
                return (int) $row['id'] !== $id;
            }
        ));
    }
}
