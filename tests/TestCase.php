<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        // The calendar's kept epoch (#753): tests set their own clocks
        \App\Services\GameDate::forget();
    }
}
