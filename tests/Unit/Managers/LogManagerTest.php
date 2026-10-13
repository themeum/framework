<?php

namespace Framework\Tests\Unit\Managers;

use Framework\Application;
use Framework\Filesystem\Filesystem;
use Framework\Managers\LogManager;
use Framework\Tests\Support\Cache\TestFilesystem;
use Framework\Tests\Unit\TestCase;

class LogManagerTest extends TestCase
{
    protected string $root;

    protected string $plugin_directory;

    protected string $uploads_directory;

    protected TestFilesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/framework-log-' . uniqid();
        $this->plugin_directory = $this->root . '/plugin';
        $this->uploads_directory = $this->root . '/uploads';

        mkdir($this->plugin_directory, 0777, true);
        mkdir($this->uploads_directory, 0777, true);

        $GLOBALS['framework_test_upload_dir'] = $this->uploads_directory;

        $this->files = new TestFilesystem();

        $this->reset_container_instance();
        $app = Application::get_instance($this->plugin_directory)->use_prefix('kirki_');
        $app->instance(Filesystem::class, $this->files);
        $app->instance('files', $this->files);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['framework_test_upload_dir'], $GLOBALS['framework_test_salt']);

        $this->files->delete($this->root, true);

        parent::tearDown();
    }

    public function test_the_default_log_is_written_under_uploads_not_the_plugin_directory()
    {
        $log = new LogManager();
        $log->error('boom');

        $this->assertStringStartsWith($this->uploads_directory . '/kirki_logs-', $log->path());
        $this->assertStringContainsString('[ERROR] boom', file_get_contents($log->path()));
        $this->assertFileDoesNotExist($this->plugin_directory . '/framework.log');
    }

    public function test_the_default_directory_name_depends_on_the_site_salt()
    {
        $first = (new LogManager())->path();

        $GLOBALS['framework_test_salt'] = 'another-site-salt';
        $second = (new LogManager())->path();

        $this->assertNotSame($first, $second);
    }

    public function test_the_default_directory_is_protected_when_it_is_created()
    {
        $log = new LogManager();
        $log->info('hello');

        $directory = dirname($log->path());

        $this->assertFileExists($directory . '/index.php');
        $this->assertStringContainsString('denied', file_get_contents($directory . '/.htaccess'));
    }

    public function test_the_legacy_log_in_the_plugin_directory_is_removed()
    {
        file_put_contents($this->plugin_directory . '/framework.log', "[ERROR] SQL: select secret\n");

        (new LogManager())->error('boom');

        $this->assertFileDoesNotExist($this->plugin_directory . '/framework.log');
    }

    public function test_an_explicit_path_is_used_unchanged_and_left_unguarded()
    {
        file_put_contents($this->plugin_directory . '/framework.log', "kept\n");
        $path = $this->root . '/custom/app.log';

        $log = new LogManager($path);
        $log->warning('careful');

        $this->assertSame($path, $log->path());
        $this->assertStringContainsString('[WARNING] careful', file_get_contents($path));
        $this->assertFileDoesNotExist($this->root . '/custom/.htaccess');
        $this->assertFileExists($this->plugin_directory . '/framework.log');
    }

    public function test_clear_empties_the_default_log()
    {
        $log = new LogManager();
        $log->error('boom');
        $log->clear();

        $this->assertSame('', file_get_contents($log->path()));
    }
}
