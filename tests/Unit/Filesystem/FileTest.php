<?php

namespace Framework\Tests\Unit\Filesystem;

use Exception;
use Framework\Container;
use Framework\Filesystem\File;
use Framework\Filesystem\Filesystem;
use Framework\Tests\Support\Cache\TestFilesystem;
use Framework\Tests\Unit\TestCase;

class FileTest extends TestCase
{
    protected string $base_dir;

    protected TestFilesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base_dir = sys_get_temp_dir() . '/framework-file-' . uniqid();
        mkdir($this->base_dir, 0777, true);

        $this->files = new TestFilesystem();
        $this->bind_filesystem($this->files);
    }

    protected function tearDown(): void
    {
        $this->files->delete($this->base_dir, true);
        unset($GLOBALS['framework_test_unwritable_paths']);

        parent::tearDown();
    }

    protected function bind_filesystem(Filesystem $filesystem): void
    {
        $container = new Container();
        $container->instance('app', $container);
        $container->instance(Filesystem::class, $filesystem);

        $this->set_container_instance($container);
    }

    protected function make_source_file(string $name = 'source.txt', string $contents = 'hello'): string
    {
        $path = $this->base_dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_move_creates_missing_target_directory(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/uploads';

        $result = (new File($source))->move($target_dir);

        $this->assertDirectoryExists($target_dir);
        $this->assertFileExists($target_dir . '/source.txt');
        $this->assertSame($target_dir . '/source.txt', $result->getPathname());
    }

    public function test_move_into_existing_directory_succeeds(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/uploads';
        mkdir($target_dir, 0777, true);

        (new File($source))->move($target_dir);

        $this->assertFileExists($target_dir . '/source.txt');
    }

    public function test_move_with_custom_name_uses_given_name(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/uploads';

        $result = (new File($source))->move($target_dir, 'renamed.txt');

        $this->assertSame('renamed.txt', basename($result->getPathname()));
        $this->assertFileExists($target_dir . '/renamed.txt');
    }

    public function test_move_sets_normalized_permissions(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/uploads';

        $result = (new File($source))->move($target_dir);

        $expected = 0666 & ~umask();
        $actual = fileperms($result->getPathname()) & 0777;

        $this->assertSame($expected, $actual);
    }

    public function test_move_throws_when_target_path_is_blocked_by_a_file(): void
    {
        $source = $this->make_source_file();
        $blocking_path = $this->base_dir . '/blocked';
        file_put_contents($blocking_path, 'i am a file, not a directory');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/similar named file exists/');

        (new File($source))->move($blocking_path);
    }

    public function test_move_throws_when_target_directory_is_not_writable(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/readonly';
        mkdir($target_dir, 0777, true);

        $GLOBALS['framework_test_unwritable_paths'] = [$target_dir];

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unable to write in the/');

        (new File($source))->move($target_dir);
    }

    public function test_move_throws_when_underlying_move_fails(): void
    {
        $source = $this->make_source_file();
        $target_dir = $this->base_dir . '/uploads';

        $this->bind_filesystem(new class extends TestFilesystem {
            public function move($path, $target)
            {
                return false;
            }
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage(sprintf(
            'Could not move the file "%s" to "%s".',
            $source,
            $target_dir . '/source.txt'
        ));

        (new File($source))->move($target_dir);
    }
}
