<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // The JSON API's limits (#380): reading, and releasing. Counted per
        // account for a request with a good token, so a classroom or several
        // Claude players behind one address don't share them (#1017); per
        // address otherwise (a made-up token counts as none)
        RateLimiter::for('api-read', fn (Request $request) => Limit::perMinute(120)->by('read|' . self::caller($request)));
        RateLimiter::for('api-write', fn (Request $request) => Limit::perMinute(10)->by('write|' . self::caller($request)));

        parent::boot();
    }

    /** Who a request counts against: its account, when its token is good, or its address */
    public static function caller(Request $request): string
    {
        $token = $request->bearerToken();
        $user = ($token ? \App\Services\Players::byToken($token) : null) ?? \App\Services\Bots::actingPlayer($request)
            // an operator's own calls (making players, their notes and feedback) count against the operator (#1052)
            ?? \App\Services\Bots::operatorFor($request->header(\App\Services\Bots::HEADER));
        return $user ? 'user:' . $user->user_id : 'ip:' . $request->ip();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
             ->namespace($this->namespace)
             ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware('api')
             ->namespace($this->namespace)
             ->group(base_path('routes/api.php'));
    }
}
