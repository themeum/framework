<?php

use Example\App\DTO\SampleDTO;
use Example\App\Events\SampleEvent;
use Example\App\Http\Controllers\BlogController;
use Example\App\Http\Controllers\EventsController;
use Example\App\Http\Controllers\SpeakersController;
use Example\App\Http\Requests\ExampleRequest;
use Example\App\Models\Blog;
use Example\App\Models\Event;
use Example\App\Resources\TestResource;
use Framework\Http\Request;
use Framework\Middlewares\AdminMiddleware;
use Framework\Middlewares\AuthMiddleware;
use Framework\Resource;
use Framework\Route;
use Framework\Supports\Arr;
use Framework\Supports\Facades\Cache;
use Framework\Supports\Facades\Cookie;
use Framework\Supports\Facades\DB;
use Framework\Supports\Facades\Http;
use Framework\Validation\Rule;
use Framework\Validation\Validator;

use function Framework\back;
use function Framework\collection;
use function Framework\dd;
use function Framework\deep_set;
use function Framework\response;
use function Framework\session;

Route::set_namespace('framework/v1');

/*
 * Rate limited routes.
 *
 * The inline form takes a maximum and a window in minutes, so this allows three
 * requests a minute per caller. The fourth answers 429 with a Retry-After header,
 * and every permitted response carries X-RateLimit-Remaining.
 */
Route::get('/ping-limited', function (Request $request) {
    return response()->json(['data' => true]);
})->throttle(5, 1);

/*
 * The named form points at a limiter registered in a service provider; see
 * Example\App\Providers\TestServiceProvider::boot(). Naming the limit keeps it in
 * one place and lets it vary by caller.
 */
Route::get('/uploads', function (Request $request) {
    return response()->json(['data' => 'uploaded']);
})->middleware('throttle:uploads');

Route::get('/ping/{name}', function (Request $request, string $name) {

    return response()->json([
        'data' => true,
        'name' => $name,
    ]);
});

Route::get('/events/{event}', [EventsController::class, 'index'])->middleware([AdminMiddleware::class, AuthMiddleware::class]);
Route::post('/events', [EventsController::class, 'create']);

Route::get('/speakers', [SpeakersController::class, 'index']);
Route::get('/speakers/{speaker}', [SpeakersController::class, 'show']);
Route::post('/speakers', [SpeakersController::class, 'create']);
Route::put('/speakers/{speaker}', [SpeakersController::class, 'update']);

Route::post('/blogs/{blog}', [BlogController::class, 'update']);

Route::get('/options', function (Request $request) {
    $events = Event::query()->get();

    return response()->json([
        'events' => Arr::last($events),
        'req' => $request->all(),
    ]);
});

Route::get('/check', function (Request $request) {
    Cache::forget('sample');

    DB::enable_query_log();
    $blog = Blog::query()->find(1);
    $query = DB::get_query_log();
    return response()->json([
        'data' => $blog,
        'query' => $query,
        'cached' => Cache::get('sample'),
    ]);
})->throttle(5, 1);
