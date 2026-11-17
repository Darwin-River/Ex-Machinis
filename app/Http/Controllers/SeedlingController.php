<?php

namespace App\Http\Controllers;

use App\Services\Seedlings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A player's seedlings on the website (#637): claim a guest's, reprogram,
 * close and mature. Behind the new-rules switch (#635).
 */
class SeedlingController extends Controller
{
    private function player(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }

    private function done(array $result, string $success)
    {
        return $result['ok']
            ? redirect('/account#seedlings')->withSuccess($success)
            : redirect('/account#seedlings')->with('error', $result['error']);
    }

    public function claim(Request $request)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Seedlings::claim($user, (string) $request->input('code'));
        return $this->done($result, $result['ok'] ? $result['name'] . ' is yours now.' : '');
    }

    public function reprogram(Request $request, int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $request->validate(['script' => 'required|string|max:' . config('forge.max_script_length')]);
        $result = Seedlings::reprogram($user, $id, str_replace("\r\n", "\n", (string) $request->input('script')));
        return $this->done($result, $result['ok'] ? 'The new code is on its way to ' . count($result['drones'])
            . ' drone(s); ' . number_format($result['fee']) . ' credits go on the seedling\'s debt, which is now '
            . number_format($result['debt']) . '.' : '');
    }

    public function close(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Seedlings::close($user, $id);
        return $this->done($result, $result['ok'] ? $result['name'] . ' is closed: it has left the game, and its debt is cancelled.' : '');
    }

    public function mature(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Seedlings::mature($user, $id);
        return $this->done($result, $result['ok'] ? $result['name'] . ' has matured: it repaid ' . number_format($result['repaid'])
            . ' credits, and its ' . number_format($result['shares']) . ' shares are yours.' : '');
    }

    /** The seedling race (#776): recent seedlings' credits by age, against the line and the starters' usual paths */
    public function race()
    {
        $race = \App\Services\SeedlingRace::build();
        $me = auth()->check() ? (int) auth()->user()->user_id : null;
        return view('world.race', compact('race', 'me'));
    }

    public function raceApi(): \Illuminate\Http\JsonResponse
    {
        return response()->json(\App\Services\SeedlingRace::build());
    }
}
