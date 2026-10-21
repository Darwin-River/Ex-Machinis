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
        //
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(\App\Services\Mail\InboxFactory::class, function () {
            return \App\Services\Mail\ImapInboxFactory::fromEnvironment();
        });

        // Add this part
        $this->app->bind('path.public', function() {
            return base_path().'/html';
        });
    }
}
