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
     * Lets a player buy shares (#1040): investing opens once they have
     * matured a company of their own. Tests of trading start their buyers
     * here, with a company that matured and has since ended, so it shows on
     * no board, holds nothing and counts against none of their limits. The
     * rule itself is tested in PlayerLadderTest.
     */
    protected function canInvest(\App\User|int $player): void
    {
        $id = $player instanceof \App\User ? (int) $player->user_id : $player;
        $now = \App\Services\World::gameNow();
        \Illuminate\Support\Facades\DB::table('companies')->insert(['name' => 'Matured once ' . $id . '-' . uniqid(), 'user_id' => $id,
            'owner_id' => $id, 'startup' => 0, 'matured_t' => $now, 'eliminated_at' => $now, 'credits' => 0]);
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
