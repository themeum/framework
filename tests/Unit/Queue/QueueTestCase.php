<?php

namespace Framework\Tests\Unit\Queue;

use Framework\Application;
use Framework\Managers\EventManager;
use Framework\Managers\LogManager;
use Framework\Queue\DatabaseQueue;
use Framework\Queue\QueueManager;
use Framework\Queue\Spawner;
use Framework\Queue\Worker;
use Framework\Tests\Support\Cache\RecordingEventManager;
use Framework\Tests\Support\Queue\ArrayDatabaseQueue;
use Framework\Tests\Support\Queue\Jobs\Journal;
use Framework\Tests\Support\Queue\RecordingLogger;
use Framework\Tests\Support\Queue\RecordingSpawner;
use Framework\Tests\Support\Queue\TestLock;
use Framework\Tests\Support\Queue\TestWorker;
use Framework\Tests\Unit\TestCase;

abstract class QueueTestCase extends TestCase
{
    protected const NOW = 1700000000;

    protected Application $app;

    protected ArrayDatabaseQueue $queue;

    protected TestWorker $worker;

    protected RecordingSpawner $spawner;

    protected RecordingEventManager $events;

    protected RecordingLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_queue_globals();
        $this->app = $this->make_application();

        $this->queue = (new ArrayDatabaseQueue())->freeze(self::NOW);
        $this->worker = new TestWorker($this->queue);
        $this->spawner = (new RecordingSpawner($this->queue, $this->worker))->freeze(self::NOW);
        $this->events = new RecordingEventManager();
        $this->log = new RecordingLogger();

        $this->app->instance(DatabaseQueue::class, $this->queue);
        $this->app->instance(Worker::class, $this->worker);
        $this->app->instance(Spawner::class, $this->spawner);
        $this->app->instance(EventManager::class, $this->events);
        $this->app->instance(LogManager::class, $this->log);
    }

    protected function tearDown(): void
    {
        $this->reset_queue_globals();

        parent::tearDown();
    }

    protected function make_application(): Application
    {
        return $this->bootstrap_application();
    }

    protected function enable_queue(): QueueManager
    {
        $manager = new QueueManager($this->queue, $this->spawner);
        $this->app->instance(QueueManager::class, $manager);

        return $manager;
    }

    protected function travel(int $seconds): void
    {
        $this->queue->travel($seconds);
        $this->spawner->travel($seconds);
    }

    protected function reset_queue_globals(): void
    {
        Journal::$entries = [];
        TestLock::$held = [];
        \WP_CLI::$messages = [];

        $GLOBALS['framework_test_actions'] = [];
        $GLOBALS['framework_test_cron'] = [];
        $GLOBALS['framework_test_salt'] = 'framework-test-salt';
    }
}
