<?php

namespace Example\App\Http\Controllers;

use Example\App\Models\Blog;
use Example\App\Services\TestService;
use Framework\Http\Request;
use Framework\Supports\Facades\Guard;

use function Framework\response;

class TestController
{
	protected TestService $service;

	public function __construct(TestService $service)
	{
		$this->service = $service;
		$this->service->increase();
	}

	public function get(Request $request, TestService $service)
	{
		return response()->json([
			'message' => 'Test Controller Loaded',
			'check' => $this->service->value() === $service->value()
		]);
	}
}
