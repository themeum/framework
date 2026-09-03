<?php

namespace Framework\Tests\Unit\View;

use Framework\Tests\Unit\TestCase;
use Framework\View\SectionManager;
use RuntimeException;

/**
 * Class SectionManagerTest.
 *
 * Run the testcase by running this command:
 * vendor/bin/phpunit --prepend tests/prepend.php --filter=SectionManagerTest --testdox
 */
class SectionManagerTest extends TestCase
{
    /**
     * @var SectionManager
     */
    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new SectionManager();
    }

    protected function tearDown(): void
    {
        while ($this->manager !== null && $this->manager->get_active() !== null) {
            $this->manager->end();
        }

        parent::tearDown();
    }

    public function test_start_and_end_captures_section_content(): void
    {
        $this->manager->start('title');
        echo 'Hello World';
        $this->manager->end();

        $this->assertSame('Hello World', $this->manager->get('title'));
    }

    public function test_get_returns_default_when_section_not_defined(): void
    {
        $this->assertSame('', $this->manager->get('missing'));
        $this->assertSame('Fallback', $this->manager->get('missing', 'Fallback'));
    }

    public function test_has_returns_true_for_defined_section(): void
    {
        $this->assertFalse($this->manager->has('title'));

        $this->manager->start('title');
        echo 'Content';
        $this->manager->end();

        $this->assertTrue($this->manager->has('title'));
    }

    public function test_clear_removes_all_sections(): void
    {
        $this->manager->start('title');
        echo 'Content';
        $this->manager->end();

        $this->assertTrue($this->manager->has('title'));

        $this->manager->clear();

        $this->assertFalse($this->manager->has('title'));
        $this->assertNull($this->manager->get_active());
    }

    public function test_start_throws_when_section_already_active(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot start section [content] while section [title] is already being captured.');

        $this->manager->start('title');

        try {
            $this->manager->start('content');
        } finally {
            $this->manager->end();
        }
    }

    public function test_end_throws_when_no_section_active(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot end section: no section is being captured.');

        $this->manager->end();
    }

    public function test_get_active_returns_active_section_name(): void
    {
        $this->assertNull($this->manager->get_active());

        $this->manager->start('content');
        $this->assertSame('content', $this->manager->get_active());

        // Clean up the output buffer started by start().
        $this->manager->end();
        $this->assertNull($this->manager->get_active());
    }

    public function test_multiple_sections_can_be_captured_sequentially(): void
    {
        $this->manager->start('title');
        echo 'Page Title';
        $this->manager->end();

        $this->manager->start('content');
        echo 'Page Content';
        $this->manager->end();

        $this->assertSame('Page Title', $this->manager->get('title'));
        $this->assertSame('Page Content', $this->manager->get('content'));
    }

    public function test_later_section_overrides_earlier_with_same_name(): void
    {
        $this->manager->start('title');
        echo 'First';
        $this->manager->end();

        $this->manager->start('title');
        echo 'Second';
        $this->manager->end();

        $this->assertSame('Second', $this->manager->get('title'));
    }
}
