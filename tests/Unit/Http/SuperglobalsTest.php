<?php

namespace Framework\Tests\Unit\Http;

use Framework\Http\Superglobals;
use Framework\Sanitizer;
use Framework\Tests\Unit\TestCase;

class SuperglobalsTest extends TestCase
{
    public function test_single_key_read_is_unslashed_and_sanitized(): void
    {
        $_POST = ['name' => "O\\'Brien <b>test</b>"];

        $this->assertSame('O\'Brien test', Superglobals::post('name'));

        $_POST = [];
    }

    public function test_single_key_read_uses_text_sanitization_by_default(): void
    {
        $_GET = ['q' => ' <script>alert(1)</script>hello '];

        $this->assertSame('alert(1)hello', Superglobals::query('q'));

        $_GET = [];
    }

    public function test_missing_key_returns_default_unsanitized(): void
    {
        $_POST = [];

        $this->assertSame('fallback <b>value</b>', Superglobals::post('missing', 'fallback <b>value</b>'));
        $this->assertNull(Superglobals::post('missing'));
    }

    public function test_reading_does_not_mutate_the_superglobal(): void
    {
        $_COOKIE = ['session' => "abc\\'123"];

        Superglobals::cookie('session', null, Sanitizer::KEY);
        $first = $_COOKIE;

        Superglobals::cookie('session', null, Sanitizer::KEY);
        $second = $_COOKIE;

        $this->assertSame(['session' => "abc\\'123"], $first);
        $this->assertSame($first, $second);

        $_COOKIE = [];
    }

    public function test_whole_array_read_is_unslashed_but_not_type_sanitized(): void
    {
        $_SERVER['REQUEST_URI'] = "/path?q=<b>raw</b>&name=O\\'Brien";

        $server = Superglobals::server();

        $this->assertSame("/path?q=<b>raw</b>&name=O'Brien", $server['REQUEST_URI']);

        unset($_SERVER['REQUEST_URI']);
    }

    public function test_non_scalar_value_is_treated_as_absent(): void
    {
        $_POST = ['name' => ['unexpected' => 'array']];

        $this->assertSame('fallback', Superglobals::post('name', 'fallback'));

        $_POST = [];
    }

    public function test_array_typed_single_key_read_returns_the_array(): void
    {
        $_GET = ['cat_ids' => ['1', '2']];

        $this->assertSame(['1', '2'], Superglobals::query('cat_ids', [], Sanitizer::ARRAY));

        $_GET = [];
    }

    public function test_array_value_is_still_rejected_when_a_non_array_type_is_requested(): void
    {
        $_GET = ['cat_ids' => ['1', '2']];

        $this->assertSame('fallback', Superglobals::query('cat_ids', 'fallback', Sanitizer::INT));

        $_GET = [];
    }

    public function test_files_returns_unslashed_whole_array(): void
    {
        $_FILES = ['upload' => ['name' => "photo\\'s.png", 'error' => 0]];

        $files = Superglobals::files();

        $this->assertSame("photo's.png", $files['upload']['name']);

        $_FILES = [];
    }
}
