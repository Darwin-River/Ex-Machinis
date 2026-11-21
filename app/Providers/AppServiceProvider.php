<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // A session whose account's password has changed since (#910) ends, and goes to Log in
        \Illuminate\Session\Middleware\AuthenticateSession::redirectUsing(fn () => url('login'));
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Add this part
        $this->app->bind('path.public', function() {
            return base_path().'/html';
        });
    }
}
