<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // The web root is html/, not Laravel's default public/. The layout
        // also reads DOCUMENT_ROOT, which is empty when running from the CLI.
        $publicPath = realpath(__DIR__.'/../html');
        $app->instance('path.public', $publicPath);
        $_SERVER['DOCUMENT_ROOT'] = $publicPath;

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
