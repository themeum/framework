<?php

namespace Framework\Tests\Unit\RateLimiting;

use Framework\Application;
use Framework\Cache\MemoTable;
use Framework\Middlewares\ThrottleRequests;
use Framework\Tests\Unit\TestCase;

abstract class RateLimiterTestCase extends TestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_limiter_globals();
        $this->app = $this->bootstrap_application();
    }

    protected function tearDown(): void
    {
        $this->reset_limiter_globals();

        parent::tearDown();
    }

    protected function reset_limiter_globals(): void
    {
        MemoTable::clear();
        ThrottleRequests::forget_headers();

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
        $GLOBALS['framework_test_current_user_id'] = 0;
        $GLOBALS['framework_test_status_header'] = null;
    }
}
