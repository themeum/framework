<?php

namespace Framework\Tests\Unit\View;

use Framework\Tests\Unit\TestCase;
use Framework\View\TemplateEngine;
use Framework\View\View;

use function Framework\view;

class TemplateEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrap_application();
    }

    public function test_normalize_template_name_supports_dot_notation(): void
    {
        $engine = new TemplateEngine();

        $this->assertSame('shop/product', $engine->normalize_template_name('shop.product'));
        $this->assertSame('shop/product', $engine->normalize_template_name('shop.product.php'));
    }

    public function test_resolve_path_finds_application_view(): void
    {
        $views = sys_get_temp_dir() . '/framework-views-' . uniqid();
        mkdir($views . '/shop', 0777, true);
        file_put_contents($views . '/shop/product.php', '<?php echo esc_html(\Framework\view_data("name"));');

        $app = $this->bootstrap_application();
        $app->use_view_path($views);
        $app->instance(\Framework\View\TemplateEngine::class, new TemplateEngine());

        $engine = $app->make(TemplateEngine::class);
        $path = $engine->resolve_path('shop.product');

        $this->assertSame(realpath($views . '/shop/product.php'), $path);

        $this->remove_directory($views);
    }

    public function test_resolve_path_applies_the_prefixed_view_path_filter_only(): void
    {
        $views = sys_get_temp_dir() . '/framework-views-' . uniqid();
        mkdir($views . '/shop', 0777, true);
        file_put_contents($views . '/shop/product.php', '');

        $app = $this->bootstrap_application();
        $app->use_prefix('kirki_');
        $app->use_view_path($views);
        $app->instance(TemplateEngine::class, new TemplateEngine());

        add_filter('framework/view/path', function () {
            return '/shared/hook/must/not/run.php';
        });
        add_filter('growfund_view_path', function () {
            return '/other/plugin/must/not/run.php';
        });
        add_filter('kirki_view_path', function ($path, $view) {
            return $path . '|' . $view;
        }, 10, 2);

        $path = $app->make(TemplateEngine::class)->resolve_path('shop.product');

        $this->assertSame(realpath($views . '/shop/product.php') . '|shop.product', $path);

        $this->remove_directory($views);
    }

    public function test_render_without_layout_returns_template_output(): void
    {
        $views = sys_get_temp_dir() . '/framework-views-' . uniqid();
        mkdir($views, 0777, true);
        file_put_contents($views . '/hello.php', 'Hello <?php echo esc_html((string) \Framework\view_data("name")); ?>');

        $app = $this->bootstrap_application();
        $app->use_view_path($views);
        $app->instance(TemplateEngine::class, new TemplateEngine());
        $app->instance(\Framework\View\ViewContext::class, new \Framework\View\ViewContext());

        $output = $app->make(TemplateEngine::class)->render('hello', ['name' => 'World'], false);

        $this->assertSame('Hello World', $output);

        $this->remove_directory($views);
    }

    public function test_view_helper_returns_view_instance(): void
    {
        $view = view('shop.product', ['id' => 1]);

        $this->assertInstanceOf(View::class, $view);
        $this->assertSame('shop.product', $view->get_template());
        $this->assertSame(['id' => 1], $view->get_data());
        $this->assertTrue($view->uses_layout());

        $view->partial();
        $this->assertFalse($view->uses_layout());
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
