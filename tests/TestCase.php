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

    /**
     * Acting as another account in the same test session: a real browser
     * switches accounts by logging in, which stores the new account's
     * password hash, so drop the old one AuthenticateSession kept (#910)
     */
    public function be(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session.store')) {
            foreach (array_keys($this->app['session.store']->all()) as $key) {
                if (str_starts_with($key, 'password_hash_')) {
                    $this->app['session.store']->forget($key);
                }
            }
        }
        return parent::be($user, $guard);
    }
}
