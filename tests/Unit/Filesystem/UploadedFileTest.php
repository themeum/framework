<?php

namespace Framework\Tests\Unit\Filesystem;

require_once __DIR__ . '/../../Support/Filesystem/UploadedFileStub.php';

use Exception;
use Framework\Container;
use Framework\Filesystem\Filesystem;
use Framework\Filesystem\UploadedFile;
use Framework\Tests\Support\Cache\TestFilesystem;
use Framework\Tests\Unit\TestCase;

class UploadedFileTest extends TestCase
{
    protected string $base_dir;

    protected TestFilesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base_dir = sys_get_temp_dir() . '/framework-uploaded-file-' . uniqid();
        mkdir($this->base_dir, 0777, true);

        $this->files = new TestFilesystem();

        $container = new Container();
        $container->instance('app', $container);
        $container->instance(Filesystem::class, $this->files);

        $this->set_container_instance($container);
    }

    protected function tearDown(): void
    {
        $this->files->delete($this->base_dir, true);

        unset(
            $GLOBALS['framework_test_uploaded_files'],
            $GLOBALS['framework_test_upload_dir'],
            $GLOBALS['framework_test_user_can']
        );

        parent::tearDown();
    }

    protected function make_tmp_upload(string $name = 'source.txt', string $contents = 'hello'): string
    {
        $path = $this->base_dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_valid_uploaded_file_moves_successfully_with_normalized_permissions(): void
    {
        $tmp = $this->make_tmp_upload();
        $GLOBALS['framework_test_uploaded_files'] = [$tmp];

        $uploaded = new UploadedFile($tmp, 'source.txt', 'text/plain', \UPLOAD_ERR_OK, strlen('hello'));

        $target_dir = $this->base_dir . '/uploads';
        $result = $uploaded->move($target_dir);

        $this->assertFileExists($target_dir . '/source.txt');

        $expected = 0666 & ~umask();
        $actual = fileperms($result->getPathname()) & 0777;

        $this->assertSame($expected, $actual);
    }

    public function test_file_not_genuinely_uploaded_is_rejected_before_any_relocation(): void
    {
        $tmp = $this->make_tmp_upload();

        // Deliberately not added to framework_test_uploaded_files: is_uploaded_file() stays false.
        $uploaded = new UploadedFile($tmp, 'source.txt', 'text/plain', \UPLOAD_ERR_OK, strlen('hello'));

        $target_dir = $this->base_dir . '/uploads';

        $this->expectException(Exception::class);

        try {
            $uploaded->move($target_dir);
        } finally {
            $this->assertFileExists($tmp);
            $this->assertDirectoryDoesNotExist($target_dir);
        }
    }

    public function test_upload_error_throws_via_error_message_without_attempting_a_move(): void
    {
        $uploaded = new UploadedFile('/nonexistent/path.txt', 'source.txt', null, \UPLOAD_ERR_NO_FILE, 0);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No file was uploaded.');

        $uploaded->move($this->base_dir . '/uploads');
    }

    public function test_store_and_store_as_succeed_end_to_end_through_filesystem_upload(): void
    {
        $GLOBALS['framework_test_upload_dir'] = $this->base_dir;

        $tmp = $this->make_tmp_upload('avatar.png', 'binary-ish contents');
        $GLOBALS['framework_test_uploaded_files'] = [$tmp];

        $uploaded = new UploadedFile($tmp, 'avatar.png', 'image/png', \UPLOAD_ERR_OK, strlen('binary-ish contents'));

        $stored_path = $uploaded->store('profile-photos');

        $this->assertFileExists($stored_path);
        $this->assertStringContainsString('profile-photos', $stored_path);
    }
}
