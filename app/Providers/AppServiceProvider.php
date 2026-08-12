<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        Model::preventLazyLoading();

        RateLimiter::for('operational', function (Request $request): Limit {
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                // A single panitia browser can keep up to nine independent
                // operational snapshots current every three seconds. Read
                // polling must not consume the mutation budget.
                return Limit::perMinute(600)
                    ->by('read:'.$request->ip());
            }

            return Limit::perMinute(180)
                ->by('write:'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('reports', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('health', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
    }
}
