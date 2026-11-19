<?php

namespace App\Http\Controllers;

use App\Services\Startups;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A player's startups on the website (#637): claim a guest's, reprogram,
 * close and mature. Behind the new-rules switch (#635).
 */
class StartupController extends Controller
{
    private function player(): ?\App\User
    {
        $user = Auth::user();
        return $user && $user->login !== null ? $user : null;
    }

    private function done(array $result, string $success)
    {
        return $result['ok']
            ? redirect('/account#startups')->withSuccess($success)
            : redirect('/account#startups')->with('error', $result['error']);
    }

    public function claim(Request $request)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Startups::claim($user, (string) $request->input('code'));
        return $this->done($result, $result['ok'] ? $result['name'] . ' is yours now.' : '');
    }

    public function reprogram(Request $request, int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $request->validate(['script' => 'required|string|max:' . config('forge.max_script_length')]);
        $result = Startups::reprogram($user, $id, str_replace("\r\n", "\n", (string) $request->input('script')));
        return $this->done($result, $result['ok'] ? 'The new code is on its way to ' . count($result['drones'])
            . ' drone(s); ' . number_format($result['fee']) . ' credits go on the startup\'s debt, which is now '
            . number_format($result['debt']) . '.' : '');
    }

    public function close(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Startups::close($user, $id);
        return $this->done($result, $result['ok'] ? $result['name'] . ' is closed: it has left the game, and its debt is cancelled.' : '');
    }

    public function mature(int $id)
    {
        if (!$user = $this->player()) {
            return redirect('/login');
        }
        $result = Startups::mature($user, $id);
        return $this->done($result, $result['ok'] ? $result['name'] . ' has matured: it repaid ' . number_format($result['repaid'])
            . ' credits, and its ' . number_format($result['shares']) . ' shares are yours.' : '');
    }

    /** The startup race (#776): recent startups' credits by age, against the line and the starters' usual paths */
    public function race()
    {
        $race = \App\Services\StartupRace::build();
        $me = auth()->check() ? (int) auth()->user()->user_id : null;
        return view('world.race', compact('race', 'me'));
    }

    public function raceApi(): \Illuminate\Http\JsonResponse
    {
        return response()->json(\App\Services\StartupRace::build());
    }
}
