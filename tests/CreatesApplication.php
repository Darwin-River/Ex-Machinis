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

        // The layout reads DOCUMENT_ROOT, which is empty when running from
        // the CLI. bootstrap/app.php already points public_path() at html/.
        $_SERVER['DOCUMENT_ROOT'] = $app->publicPath();

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
