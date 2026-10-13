<?php

namespace Framework\Tests\Unit;

use Exception;
use Framework\ApiExceptionHandler;
use Framework\Exceptions\HttpException;
use Framework\Exceptions\QueryException;
use Framework\Http\Response;
use Framework\Managers\LogManager;
use Framework\SiteExceptionHandler;
use Framework\Tests\Support\Queue\RecordingLogger;
use RuntimeException;

class ExceptionHandlerTest extends TestCase
{
    protected RecordingLogger $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new RecordingLogger();
        $this->bootstrap_application()->instance(LogManager::class, $this->log);
    }

    public function test_the_site_handler_hides_a_server_error_message_from_visitors()
    {
        $message = $this->render_site_page(SiteExceptionHandler::class, $this->query_exception());

        $this->assertSame('Internal Server Error', $message);
        $this->assertSame(Response::INTERNAL_SERVER_ERROR, $GLOBALS['framework_test_status_header']);
    }

    public function test_the_site_handler_still_logs_the_hidden_message()
    {
        $this->render_site_page(SiteExceptionHandler::class, $this->query_exception());

        $this->assertCount(1, $this->log->errors);
        $this->assertStringContainsString('secret_table', $this->log->errors[0]);
    }

    public function test_the_site_handler_keeps_a_client_error_message()
    {
        $message = $this->render_site_page(SiteExceptionHandler::class, new Exception('Not Found', 404));

        $this->assertSame('Not Found', $message);
        $this->assertSame(Response::NOT_FOUND, $GLOBALS['framework_test_status_header']);
    }

    public function test_the_site_handler_keeps_a_declared_http_exception_message()
    {
        $message = $this->render_site_page(
            SiteExceptionHandler::class,
            new HttpException('Gateway timed out', Response::SERVICE_UNAVAILABLE)
        );

        $this->assertSame('Gateway timed out', $message);
    }

    public function test_the_site_handler_shows_a_server_error_message_in_debug_mode()
    {
        $message = $this->render_site_page(DebugSiteExceptionHandler::class, $this->query_exception());

        $this->assertStringContainsString('secret_table', $message);
    }

    public function test_the_api_handler_hides_a_server_error_message_from_clients()
    {
        $response = ApiExceptionHandler::get_response($this->query_exception());

        $this->assertSame(Response::INTERNAL_SERVER_ERROR, $response->get_status());
        $this->assertSame('Internal Server Error', $response->get_content(true)['message']);
    }

    public function test_the_api_handler_logs_the_hidden_message()
    {
        ApiExceptionHandler::get_response($this->query_exception());

        $this->assertCount(1, $this->log->errors);
        $this->assertStringContainsString('secret_table', $this->log->errors[0]);
    }

    public function test_the_api_handler_keeps_a_client_error_message()
    {
        $response = ApiExceptionHandler::get_response(new Exception('Gone', 410));

        $this->assertSame(410, $response->get_status());
        $this->assertSame('Gone', $response->get_content(true)['message']);
    }

    public function test_the_api_handler_returns_a_server_error_message_in_debug_mode()
    {
        $response = DebugApiExceptionHandler::get_response($this->query_exception());

        $this->assertStringContainsString('secret_table', $response->get_content(true)['message']);
    }

    protected function query_exception(): QueryException
    {
        return new QueryException(
            'select * from secret_table where email = ?',
            ['user@example.com'],
            new Exception("Table 'secret_table' doesn't exist", 1146)
        );
    }

    protected function render_site_page(string $handler, Exception $exception): string
    {
        try {
            $handler::handle($exception);
        } catch (RuntimeException $stopped) {
            return $stopped->getMessage();
        }

        $this->fail('The handler should have stopped the request.');
    }
}

class DebugSiteExceptionHandler extends SiteExceptionHandler
{
    protected static function is_debug()
    {
        return true;
    }
}

class DebugApiExceptionHandler extends ApiExceptionHandler
{
    protected static function is_debug()
    {
        return true;
    }
}
