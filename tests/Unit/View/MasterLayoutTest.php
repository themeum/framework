<?php

namespace Framework\Tests\Unit\View;

use Framework\Tests\Unit\TestCase;
use Framework\View\SectionManager;
use Framework\View\TemplateEngine;
use Framework\View\View;
use Framework\View\ViewContext;
use RuntimeException;

use function Framework\app;
use function Framework\view;

/**
 * Class MasterLayoutTest.
 *
 * Run the testcase by running this command:
 * vendor/bin/phpunit --prepend tests/prepend.php --filter=MasterLayoutTest --testdox
 */
class MasterLayoutTest extends TestCase
{
    /**
     * Temporary views directory.
     *
     * @var string
     */
    protected $views;

    protected function setUp(): void
    {
        parent::setUp();

        $this->views = sys_get_temp_dir() . '/framework-master-layout-' . uniqid();
        mkdir($this->views . '/site/account', 0777, true);

        $app = $this->bootstrap_application();
        $app->use_view_path($this->views);
        $app->instance(TemplateEngine::class, new TemplateEngine());
        $app->instance(ViewContext::class, new ViewContext());
        $app->instance(SectionManager::class, new SectionManager());
    }

    protected function tearDown(): void
    {
        $this->remove_directory($this->views);

        parent::tearDown();
    }

    public function test_view_layout_accepts_string(): void
    {
        $view = view('shop.product', ['id' => 1]);

        $view->layout('site.account.master');

        $this->assertTrue($view->uses_layout());
        $this->assertSame('site.account.master', $view->get_master_layout());
    }

    public function test_view_layout_true_returns_null_master(): void
    {
        $view = view('shop.product');

        $view->layout(true);

        $this->assertTrue($view->uses_layout());
        $this->assertNull($view->get_master_layout());
    }

    public function test_view_layout_false_disables_layout(): void
    {
        $view = view('shop.product');

        $view->layout(false);

        $this->assertFalse($view->uses_layout());
        $this->assertNull($view->get_master_layout());
    }

    public function test_partial_overrides_string_layout(): void
    {
        $view = view('shop.product');
        $view->layout('site.account.master');
        $view->partial();

        $this->assertFalse($view->uses_layout());
        $this->assertNull($view->get_master_layout());
    }

    public function test_render_with_master_layout_composes_sections(): void
    {
        // Child template: defines title and content sections.
        file_put_contents(
            $this->views . '/site/account/dashboard.php',
            '<?php'
            . ' \Framework\start_section("title"); echo "Dashboard"; \Framework\end_section();'
            . ' \Framework\start_section("content"); echo "<p>Welcome</p>"; \Framework\end_section();'
        );

        // Master layout: yields sections.
        file_put_contents(
            $this->views . '/site/account/master.php',
            '<header><?php \Framework\render_section("title", "Default Title"); ?></header>'
            . '<main><?php \Framework\render_section("content"); ?></main>'
        );

        $engine = app(TemplateEngine::class);
        $output = $engine->render('site.account.dashboard', [], 'site.account.master');

        $this->assertSame(
            '<header>Dashboard</header><main><p>Welcome</p></main>',
            $output
        );
    }

    public function test_render_with_master_layout_uses_default_for_missing_section(): void
    {
        // Child template: only defines content.
        file_put_contents(
            $this->views . '/site/account/dashboard.php',
            '<?php \Framework\start_section("content"); echo "Body"; \Framework\end_section();'
        );

        // Master layout: title section has a default, actions section is empty by default.
        file_put_contents(
            $this->views . '/site/account/master.php',
            '<h1><?php \Framework\render_section("title", "My Account"); ?></h1>'
            . '<div><?php \Framework\render_section("actions"); ?></div>'
            . '<main><?php \Framework\render_section("content"); ?></main>'
        );

        $engine = app(TemplateEngine::class);
        $output = $engine->render('site.account.dashboard', [], 'site.account.master');

        $this->assertSame(
            '<h1>My Account</h1><div></div><main>Body</main>',
            $output
        );
    }

    public function test_render_with_master_layout_throws_when_master_not_found(): void
    {
        file_put_contents($this->views . '/site/account/dashboard.php', '<?php echo "child";');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Master layout [site.account.nonexistent] not found.');

        $engine = app(TemplateEngine::class);
        $engine->render('site.account.dashboard', [], 'site.account.nonexistent');
    }

    public function test_sections_are_cleared_after_render(): void
    {
        file_put_contents(
            $this->views . '/site/account/dashboard.php',
            '<?php \Framework\start_section("title"); echo "First"; \Framework\end_section();'
        );
        file_put_contents(
            $this->views . '/site/account/master.php',
            '<?php \Framework\render_section("title"); ?>'
        );

        $engine = app(TemplateEngine::class);
        $engine->render('site.account.dashboard', [], 'site.account.master');

        // Sections should be cleared after render.
        $sections = app(SectionManager::class);
        $this->assertFalse($sections->has('title'));
    }

    protected function remove_directory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
