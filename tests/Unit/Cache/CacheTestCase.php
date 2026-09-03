<?php

namespace Framework\Tests\Unit\Cache;

use Framework\Application;
use Framework\Cache\MemoTable;
use Framework\Tests\Unit\TestCase;

abstract class CacheTestCase extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_cache_globals();
        $this->app = $this->bootstrap_application();
    }

    protected function tearDown(): void
    {
        $this->reset_cache_globals();

        parent::tearDown();
    }

    protected function reset_cache_globals(): void
    {
        MemoTable::clear();

        $GLOBALS['framework_test_transients'] = [];
        $GLOBALS['framework_test_site_transients'] = [];
        $GLOBALS['framework_test_options'] = [];
        $GLOBALS['framework_test_site_options'] = [];
        $GLOBALS['framework_test_ext_object_cache'] = false;
        $GLOBALS['framework_test_object_cache'] = [];
        $GLOBALS['framework_test_multisite'] = false;
        $GLOBALS['framework_test_network_id'] = 1;
        $GLOBALS['framework_test_blog_id'] = 1;
        $GLOBALS['framework_test_salt'] = 'framework-test-salt';
        $GLOBALS['framework_test_actions'] = [];
    }
}
