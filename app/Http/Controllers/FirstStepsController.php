<?php

namespace App\Http\Controllers;

use App\Services\FirstSteps;
use App\Services\Loans;
use App\Services\Ownership;
use App\Services\Pipeline;
use Illuminate\Support\Facades\DB;

/**
 * A first share and a first loan (#1044, part of #1039): one card and one
 * button each. "Claim my shares" (#1040) lands on the first share.
 */
class FirstStepsController extends Controller
{
    private function player(): ?\App\User
    {
        $u = auth()->user();
        return $u && $u->login !== null ? $u : null;
    }

    public function share()
    {
        $user = $this->player();
        $path = Pipeline::for($user);
        return view('pages.first-share', [
            'path' => $path, 'user' => $user,
            'mayInvest' => $user ? Ownership::mayInvest((int) $user->user_id) : false,
            'pick' => FirstSteps::share($user),
            'claimed' => session('claimed'),
            'bought' => session('success') !== null && Pipeline::step($path, 'invest')['done'],
            'next' => Pipeline::step($path, 'lend'),
        ]);
    }

    public function loan()
    {
        $user = $this->player();
        $path = Pipeline::for($user);
        return view('pages.first-loan', [
            'path' => $path, 'user' => $user,
            'pick' => FirstSteps::loan($user),
            'credits' => $user ? (int) DB::table('users')->where('user_id', $user->user_id)->value('credits') : null,
            'min' => Loans::minCredits(),
        ]);
    }
}
