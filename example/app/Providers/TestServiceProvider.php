<?php

namespace Example\App\Providers;

use Framework\Http\Request;
use Framework\RateLimiting\Limit;
use Framework\ServiceProvider;
use Framework\Supports\Facades\RateLimiter;

use function Framework\config;

class TestServiceProvider extends ServiceProvider
{
    public function register()
    {
        $config = config('app.name');
    }

    public function boot()
    {
        /*
         * A named limiter. The callback receives the request, so the limit can depend on
         * who is asking: signed in callers get a larger allowance than guests.
         */
        RateLimiter::for('uploads', function (Request $request) {
            return $request->user_id()
                ? Limit::per_minute(100)->by('user:' . $request->user_id())
                : Limit::per_minute(10)->by('ip:' . $request->ip());
        });

        /*
         * A limiter may return several limits. Each is counted separately and the first one
         * exceeded rejects the request, so this allows bursts of ten a minute up to a
         * thousand a day.
         */
        RateLimiter::for('reports', function (Request $request) {
            $caller = $request->user_id() ?: $request->ip();

            return [
                Limit::per_minute(10)->by('minute:' . $caller),
                Limit::per_day(1000)->by('day:' . $caller),
            ];
        });
    }
}
